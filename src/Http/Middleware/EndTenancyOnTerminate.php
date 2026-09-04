<?php

namespace Splicewire\Beam\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends tenancy when the request ends, so the next request in the same process starts central.
 *
 * ## Why this is not optional under a persistent worker
 *
 * Tenancy here is a Postgres `search_path` swap on a connection
 * ({@see \Splicewire\Beam\Tenancy\PostgreSQLSchemaManager}). Under request-per-process PHP the process
 * dies and takes the connection with it, so a request that never ended tenancy costs nothing. Under
 * Octane/FrankenPHP the worker and its connection outlive the request, and the next request — a
 * central one, or one whose tenant identification falls through — inherits the previous tenant's
 * `search_path`.
 *
 * That failure is **silent**, which is what makes it worth a middleware rather than a convention.
 * Because the schema manager sets `search_path = "$tenant,public"`, a leaked frame does not error on a
 * missing table; every query resolves against the wrong tenant's copy and returns rows. Nothing in the
 * response says which tenant answered.
 *
 * ## The mechanism already existed; the caller did not
 *
 * `Tenancy::end()` fires `TenancyEnded`, which stancl's `RevertToCentralContext` turns into `revert()`
 * on every registered bootstrapper — including `DatabaseTenancyBootstrapper::revert()`, which
 * reconnects to central. None of that is new. What was missing is anything invoking it at the end of a
 * web request: stancl registers no terminating middleware and no `terminate` hook of any kind
 * (`grep -F 'function terminate' vendor/stancl/tenancy/src` → 0, measured 2026-09-04 against v3).
 *
 * The estate's own test suite is the evidence that this gap is real and already worked around by hand:
 * 147 test files call `cleanUpTenantState()`, whose first act is exactly this `end()`, because
 * `TestCase::tearDown()` does not end tenancy either. A worker has no `beforeEach`.
 *
 * ## Why `terminate()` and not an Octane event listener
 *
 * `terminate()` is framework-native and runs identically under FPM, `artisan serve`, and Octane —
 * Octane calls `$kernel->terminate($request, $response)` on the same contract. Listening to Octane's
 * `RequestTerminated` instead would make this package depend on `laravel/octane`, and would leave the
 * non-Octane hosts (most of the estate) without the reset they will need the day they switch.
 *
 * Registered onto the global stack rather than a route group so it cannot be missed by a route that
 * forgot it — see {@see \Splicewire\Beam\Tenancy\BeamTenancyServiceProvider::registerTenancyTerminator()}.
 */
class EndTenancyOnTerminate
{
    public function __construct(protected Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // `bound()` rather than a bare resolve: stancl binds `Tenancy` as a singleton in its own
        // provider, so a true here means tenancy is genuinely wired. Resolving unconditionally would
        // AUTO-RESOLVE a fresh, never-initialized instance at a host that does not install stancl —
        // harmless, but it manufactures an object to ask it a question whose answer is already known.
        if (! $this->app->bound(Tenancy::class)) {
            return;
        }

        $tenancy = $this->app->make(Tenancy::class);

        // Idempotent by construction: `end()` is a no-op on an uninitialized tenancy, but checking
        // first keeps a central request from firing `EndingTenancy` on every single response.
        if ($tenancy->initialized) {
            $tenancy->end();
        }
    }
}
