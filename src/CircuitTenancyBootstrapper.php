<?php

namespace Splicewire\Beam\Tenancy;

use Splicewire\Beam\Tenancy\Contracts\TenantCapabilityOverlay;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Hydrate the per-tenant Conduit capability overlay on each tenant switch, and tear it
 * down on revert — mirrors {@see PermissionsTenancyBootstrapper}. Because the capability
 * overlay is one in-memory layer resolved once in the central context, without a reset on
 * revert one tenant's Conduits would leak into the next tenant's request on a shared worker
 * (exactly the failure mode the permission bootstrapper guards against). Registered *after*
 * DatabaseTenancyBootstrapper so the tenant schema is already reachable when hydration queries it.
 *
 * Depends on the {@see TenantCapabilityOverlay} port (issue 02), never the engine concretes —
 * so this authority lifts into `laravel-tower-tenancy` depending DOWN; the host binds the
 * adapter over `ConduitHydrator`/`CapabilityRegistry`.
 */
class CircuitTenancyBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        app(TenantCapabilityOverlay::class)->hydrate($tenant->getTenantKey());
    }

    public function revert(): void
    {
        app(TenantCapabilityOverlay::class)->reset();
    }
}
