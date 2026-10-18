<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Tenancy\Exceptions\SoleTenantNotIdentified;
use Splicewire\Beam\Tenancy\Http\Middleware\InitializeTenancyBySoleTenant;
use Splicewire\Beam\Tenancy\Resolvers\SoleTenantResolver;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\RecordingBootstrapper;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Tenancy;

/**
 * The `sole` topology's identification (tower-is-splicewire ticket 07, D4′): a sole-tenant install identifies its
 * only eligible tenant from the install's own state, and fails CLOSED when it has none or more than one. The
 * designated system tenant is not eligible. Nothing on the request (host, path, header, seat, session) is read.
 */
beforeEach(function () {
    // Bound explicitly, as EndTenancyOnTerminateTest explains: unbound, `tenancy()` and `tenant()` would assert nothing.
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    config()->set('tenancy.bootstrappers', [RecordingBootstrapper::class]);

    Route::middleware([InitializeTenancyBySoleTenant::class])
        ->get('sole-probe', fn () => response()->json(['tenant' => tenant()?->getTenantKey()]));
});

afterEach(function () {
    tenancy()->end();
});

it('identifies the only tenant of the install', function () {
    Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    $this->getJson('sole-probe')->assertOk()->assertJson(['tenant' => 'acme']);
});

it('does not count the designated system tenant as eligible', function () {
    Tenant::provisionSystem();
    Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    $this->getJson('sole-probe')->assertOk()->assertJson(['tenant' => 'acme']);
});

it('refuses an empty install with 503, and a system tenant alone is still empty', function () {
    $empty = $this->getJson('sole-probe');
    expect($empty->status())->toBe(503)
        ->and($empty->json('message'))->toBe('This sole-tenant install has no tenant yet.')
        ->and(tenancy()->initialized)->toBeFalse();

    Tenant::provisionSystem();

    expect($this->getJson('sole-probe')->status())->toBe(503);
});

it('refuses an ambiguous install with 503 rather than picking one', function () {
    Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    Tenant::create(['id' => 'globex', 'name' => 'Globex']);
    Tenant::provisionSystem();

    $ambiguous = $this->getJson('sole-probe');
    expect($ambiguous->status())->toBe(503)
        ->and($ambiguous->json('message'))->toContain('more than one')
        ->and(tenancy()->initialized)->toBeFalse();
});

it('stays ambiguous when several system tenants precede two eligible ones (orch-peer HIGH, 5fafc48d)', function () {
    // Two tenants carry the system role and sort FIRST, then two eligible tenants. A resolver that limits before it
    // excludes system tenants sees [a, b, c], drops a and b, and wrongly serves c.
    foreach (['a-system', 'b-system'] as $id) {
        Tenant::create(['id' => $id, 'name' => $id])->markAsSystem();
    }
    Tenant::create(['id' => 'c-eligible', 'name' => 'C']);
    Tenant::create(['id' => 'd-eligible', 'name' => 'D']);

    $ambiguous = $this->getJson('sole-probe');
    expect($ambiguous->status())->toBe(503)
        ->and($ambiguous->json('message'))->toContain('more than one')
        ->and(tenancy()->initialized)->toBeFalse();
});

it('identifies the one eligible tenant behind several system tenants', function () {
    foreach (['a-system', 'b-system', 'c-system'] as $id) {
        Tenant::create(['id' => $id, 'name' => $id])->markAsSystem();
    }
    Tenant::create(['id' => 'd-eligible', 'name' => 'D']);

    $this->getJson('sole-probe')->assertOk()->assertJson(['tenant' => 'd-eligible']);
});

it('ignores whatever tenant the request or its principal might name', function () {
    Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    // A tenant-shaped header, query value and host: none is an identification input in this mode.
    $this->getJson('http://globex.example.test/sole-probe?tenant=globex', ['X-Tenant' => 'globex'])
        ->assertOk()->assertJson(['tenant' => 'acme']);
});

it('raises a stancl identification failure that carries a 503', function () {
    $resolver = app(SoleTenantResolver::class);

    expect(fn () => $resolver->resolve())->toThrow(SoleTenantNotIdentified::class);

    try {
        $resolver->resolve();
    } catch (SoleTenantNotIdentified $e) {
        expect($e)->toBeInstanceOf(TenantCouldNotBeIdentifiedException::class)
            ->and($e->getStatusCode())->toBe(503);
    }
});
