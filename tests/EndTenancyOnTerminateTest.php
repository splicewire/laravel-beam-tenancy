<?php

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Splicewire\Beam\Tenancy\Http\Middleware\EndTenancyOnTerminate;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\RecordingBootstrapper;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Tenancy;

/**
 * Tenancy must END when a request ends.
 *
 * Under request-per-process PHP this is free: the process dies and takes the connection with it. Under
 * a persistent worker (Octane/FrankenPHP) the connection outlives the request, so a request that
 * bootstrapped tenancy and never ended it leaves the NEXT request pointed at the previous tenant's
 * schema — and because {@see \Splicewire\Beam\Tenancy\PostgreSQLSchemaManager} sets
 * `search_path = "$tenant,public"`, the wrong schema still satisfies every query. It resolves silently
 * to another tenant's rows rather than erroring.
 *
 * The revert MECHANISM already exists and works: `tenancy()->end()` fires `TenancyEnded`, which
 * `RevertToCentralContext` turns into a `revert()` on every bootstrapper. What was missing is anything
 * calling it at the end of a request. The estate's own test suite is the evidence — 147 files call
 * `cleanUpTenantState()` by hand, and its first two lines are exactly this `end()`.
 *
 * These tests assert the mechanism through the framework's real terminate path rather than by calling
 * `end()` directly, because the defect was never that `end()` was broken.
 */
beforeEach(function () {
    // stancl's `Tenancy` is a plain class, so `app(Tenancy::class)` AUTO-RESOLVES under testbench —
    // which does not auto-discover stancl's provider. Unbound, every `tenancy()` call would return a
    // FRESH instance, so `initialize()` here and `initialized` there would be different objects and
    // this test would pass while asserting nothing. Bound explicitly, per the estate's testbench rule.
    app()->singleton(Tenancy::class);

    // And the `Tenant` CONTRACT, which stancl's provider binds off the tenancy instance
    // (`TenancyServiceProvider:40`). Without it the `tenant()` helper returns null unconditionally —
    // so an assertion like `expect(tenant())->toBeNull()` would pass whether tenancy had ended or not.
    // That is this estate's signature defect (an instrument that cannot distinguish "nothing there"
    // from "didn't look"), and it was live in the first draft of this very file.
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);

    config()->set('tenancy.bootstrappers', [RecordingBootstrapper::class]);

    // The stancl core wiring: bootstrap on init, revert on end. Without these, `end()` fires an event
    // nothing listens to and the revert assertion below would be vacuous.
    Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
    Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);

    RecordingBootstrapper::reset();
});

function terminateOneRequest(): void
{
    app(HttpKernel::class)->terminate(Request::create('/'), new Response);
}

it('ends tenancy when the request terminates', function () {
    $tenant = Tenant::create(['id' => 'acme', 'slug' => 'acme']);

    tenancy()->initialize($tenant);

    expect(tenancy()->initialized)->toBeTrue()
        ->and(RecordingBootstrapper::$bootstrapped)->toBe(1)
        ->and(RecordingBootstrapper::$reverted)->toBe(0);

    terminateOneRequest();

    expect(tenancy()->initialized)->toBeFalse()
        ->and(RecordingBootstrapper::$reverted)->toBe(1);
});

it('leaves the next request in central context after a tenant request', function () {
    $acme = Tenant::create(['id' => 'acme', 'slug' => 'acme']);
    $globex = Tenant::create(['id' => 'globex', 'slug' => 'globex']);

    // Request 1 — a tenant request, terminated the way the framework terminates one.
    tenancy()->initialize($acme);
    terminateOneRequest();

    // Request 2 arrives in the SAME process. Under a worker this is the leak: without the reset it
    // would still be inside acme's context, and a central request would read acme's rows.
    expect(tenancy()->initialized)->toBeFalse()
        ->and(tenant())->toBeNull();

    // And a second tenant initializes cleanly rather than stacking on the first.
    tenancy()->initialize($globex);
    expect(tenant('id'))->toBe('globex');

    terminateOneRequest();
    expect(tenancy()->initialized)->toBeFalse();
});

it('is harmless when the request never bootstrapped tenancy', function () {
    expect(tenancy()->initialized)->toBeFalse();

    terminateOneRequest();

    // A central request must not pay for, or break on, a revert that has nothing to revert.
    expect(tenancy()->initialized)->toBeFalse()
        ->and(RecordingBootstrapper::$reverted)->toBe(0);
});

it('is actually registered in the global middleware stack', function () {
    // The estate's signature defect is a declaration nothing consumes. A terminable middleware that
    // is never pushed onto the kernel is exactly that: it would pass every unit test of its own
    // `terminate()` method and never run in production.
    $kernel = app(HttpKernel::class);

    $reflected = new ReflectionClass($kernel);
    $property = $reflected->getProperty('middleware');
    $property->setAccessible(true);

    expect($property->getValue($kernel))->toContain(EndTenancyOnTerminate::class);
});
