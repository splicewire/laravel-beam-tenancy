<?php

namespace Splicewire\Beam\Tenancy;

use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * The tenant tier of beam's Override Ladder (laravel-beam ADR-0245; config-sections ticket 07): inside a tenant hop the
 * tenant's PUBLISHED config overrides apply on top of the central effective configuration, and revert to it when the
 * hop ends.
 *
 * List it in `tenancy.bootstrappers` AFTER `DatabaseTenancyBootstrapper` and `CacheTenancyBootstrapper` (stancl runs them
 * in list order and reverts them in reverse): the tenant's rows live on the tenant's own connection, schema, pool or
 * database, so the database switch must have happened. The `TenantConfig` / `TenantModelCatalog` / `TenantRoleAlias`
 * features run after every bootstrapper (on `TenancyBootstrapped`), so the role alias still runs after the catalog merge.
 *
 * - **bootstrap**: first bring the CURRENT repository to the operator tier's published state ({@see ConfigOverlay::sync()};
 *   a no-op when the request's or job's own operator sync already did), then lay the tenant's layer on top
 *   ({@see ConfigTenantOverlay::apply()}). So the operator sync always precedes the tenant tier, whichever of the two
 *   `JobProcessing` listeners (stancl's queue bootstrapper's and beam's operator sync) runs first.
 * - **revert**: put back the central values the tenant layer replaced ({@see ConfigTenantOverlay::revert()}): the central
 *   EFFECTIVE values, an operator override included, never the package defaults.
 *
 * Holds nothing: HANDBACK-03's rule (config-sections 03) is that a ladder hook never holds a config repository; both calls
 * resolve `app('config')` themselves, so under Octane a hop writes only the request's clone, and a queue worker (which has
 * no clone) is restored exactly.
 */
class ConfigLadderTenancyBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        app(ConfigOverlay::class)->sync();
        // config-sections 09: apply() also runs the overlay's contributors, beam-tenancy's cutover source among them, which
        // lays the tenant's legacy `tenants.data` values (here and on every refresh after a tenant publish).
        app(ConfigTenantOverlay::class)->apply();
    }

    public function revert(): void
    {
        app(ConfigTenantOverlay::class)->revert();
    }
}
