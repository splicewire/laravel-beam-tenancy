<?php

namespace Splicewire\Beam\Tenancy\Contracts;

/**
 * The per-tenant capability-overlay seam (self-hosted extraction issue 02 — the
 * tenancy analogue of issue 01's bound contracts). `CircuitTenancyBootstrapper`
 * hydrates a tenant's Conduit capability overlay on each tenant switch and resets
 * it on revert; it must do so **without naming the engine concretes**
 * (`App\Circuit\CapabilityRegistry`, `App\Conduits\ConduitHydrator`) so the
 * tenancy authority can lift into `laravel-tower-tenancy` depending DOWN, not up
 * on the engine.
 *
 * The host binds a concrete adapter (`ConduitCapabilityOverlay`) that delegates
 * to those engine services. This port is owned by the tenancy tier; the engine
 * fulfils it.
 */
interface TenantCapabilityOverlay
{
    /** Hydrate the tenant's capability overlay on switch-in. */
    public function hydrate(string $tenantKey): void;

    /** Tear the overlay down on revert (prevents cross-tenant leakage on a shared worker). */
    public function reset(): void;
}
