<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Support\ServiceProvider;

class BeamMultiTenancyServiceProvider extends ServiceProvider
{
    /**
     * Register the package's configuration.
     *
     * Beam config keys use the product word, not the `splicewire` vendor
     * (ADR-0092; precedent: laravel-beam-accounts). The source ships as a flat
     * `config/beam-tenancy.php` and is both merged and published to the
     * same flat path in the host app, so app authors see
     * `config/beam-tenancy.php` and reach keys at
     * `config('beam-tenancy.*')`.
     */
    public function register(): void
    {
        $source = __DIR__.'/../config/beam-tenancy.php';

        $this->mergeConfigFrom($source, 'beam-tenancy');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $source => $this->app->configPath('beam-tenancy.php'),
            ], 'beam-tenancy-config');
        }
    }
}
