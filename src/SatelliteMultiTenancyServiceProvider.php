<?php

namespace Splicewire\SatelliteMultiTenancy;

use Illuminate\Support\ServiceProvider;

class SatelliteMultiTenancyServiceProvider extends ServiceProvider
{
    /**
     * Register the package's configuration.
     *
     * This inlines splicewire's "PublishesSplicewireConfig loud-nested convention"
     * rather than depending on laravel-satellite: the config source lives under a
     * nested `config/splicewire/multi_tenancy.php` path and is both merged and
     * published to the same loud-nested path in the host app, so app authors see
     * `config/splicewire/multi_tenancy.php` and reach keys at
     * `config('splicewire.multi_tenancy.*')`.
     */
    public function register(): void
    {
        $source = __DIR__.'/../config/splicewire/multi_tenancy.php';

        $this->mergeConfigFrom($source, 'splicewire.multi_tenancy');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $source => $this->app->configPath('splicewire/multi_tenancy.php'),
            ], 'splicewire-multi_tenancy-config');
        }
    }
}
