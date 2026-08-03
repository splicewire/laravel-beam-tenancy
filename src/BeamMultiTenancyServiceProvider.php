<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Support\ServiceProvider;

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
}
