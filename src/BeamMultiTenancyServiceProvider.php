<?php

namespace Splicewire\Beam\MultiTenancy;

use Illuminate\Support\ServiceProvider;

class BeamMultiTenancyServiceProvider extends ServiceProvider
{
    /**
     * Register the package's configuration.
     *
     * Beam config keys use the product word, not the `splicewire` vendor
     * (ADR-0092; precedent: laravel-beam-accounts). The source ships as a flat
     * `config/beam-multi-tenancy.php` and is both merged and published to the
     * same flat path in the host app, so app authors see
     * `config/beam-multi-tenancy.php` and reach keys at
     * `config('beam-multi-tenancy.*')`.
     */
    public function register(): void
    {
        $source = __DIR__.'/../config/beam-multi-tenancy.php';

        $this->mergeConfigFrom($source, 'beam-multi-tenancy');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $source => $this->app->configPath('beam-multi-tenancy.php'),
            ], 'beam-multi-tenancy-config');
        }
    }
}
