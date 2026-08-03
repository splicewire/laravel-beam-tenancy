<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Sitemap\Resolvers\ConfigSitemapBaseUrlResolver;
use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Tenancy\Sitemap\TenantSitemapBaseUrlResolver;

class BeamMultiTenancyServiceProvider extends ServiceProvider
{
    /**
     * Back-compat aliases for the 2 Tenant* wire DTOs that moved DOWN from
     * `Splicewire\Tower\Data\*` into this package (recohere Lane A cluster 3). A
     * straggler safety-net: any consumer still typing the old tower FQCN keeps
     * resolving for one release.
     */
    private const BACK_COMPAT_DTOS = [
        'TenantInvitationData',
        'TenantMemberData',
    ];

    /**
     * Register the package's configuration.
     *
     * Beam config keys use the product word, not the `splicewire` vendor
     * (ADR-0092; precedent: laravel-beam-accounts). The source ships as a nested
     * `config/beam/tenancy.php` and is both merged and published to the
     * same nested path in the host app, so app authors see
     * `config/beam/tenancy.php` and reach keys at
     * `config('beam.tenancy.*')`.
     */
    public function register(): void
    {
        foreach (self::BACK_COMPAT_DTOS as $dto) {
            $old = 'Splicewire\\Tower\\Data\\'.$dto;
            $new = 'Splicewire\\Beam\\Tenancy\\Data\\'.$dto;

            if (! class_exists($old, false)) {
                class_alias($new, $old);
            }
        }

        $source = __DIR__.'/../config/beam/tenancy.php';

        $this->mergeConfigFrom($source, 'beam.tenancy');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $source => $this->app->configPath('beam/tenancy.php'),
            ], 'beam-tenancy-config');
        }
    }

    /**
     * Re-bind the sitemap base-URL port to the multi-domain resolver (ADR-0166 §3).
     * beam-sitemap binds the config-default in its own `register()`; we override it here
     * in `boot()` (after all providers have registered) with a resolver that returns the
     * active tenant's domain, falling back to whatever base-URL resolver was previously
     * bound — the config-default in a normal install — when tenancy is absent.
     *
     * Guarded on the port interface existing, so a host that installs beam-tenancy without
     * the beam-sitemap arm never trips this. Cross-tenant aggregation is deferred to tower
     * (ADR-0166 §5) — not bound here.
     */
    public function boot(): void
    {
        if (! interface_exists(SitemapBaseUrlResolver::class)) {
            return;
        }

        $this->app->singleton(SitemapBaseUrlResolver::class, function ($app) {
            // The fallback used when tenancy is absent: the config-default. Constructed
            // directly (not re-resolved off the container, which we are overriding) so
            // this binding can never recurse into itself.
            $fallback = $app->make(ConfigSitemapBaseUrlResolver::class);

            return new TenantSitemapBaseUrlResolver($app, $fallback);
        });
    }
}
