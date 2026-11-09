<?php

use Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver;
use Splicewire\Beam\Analytics\Contracts\MeasurementSettings;
use Splicewire\Beam\Analytics\Providers\GaProvider;
use Splicewire\Beam\Analytics\Providers\GtmProvider;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Analytics\Settings\ConfigMeasurementSettings;
use Splicewire\Beam\Tenancy\Analytics\TenantMeasurementSettings;
use Splicewire\Beam\Tenancy\Site\SiteSettings;

/**
 * analytics-area 05: the Measurement area reads a tenant's own measurement id from its Site Settings, through analytics'
 * MeasurementSettings port, which this package rebinds. In Tenant scope it never answers with the install's ids, and it
 * never asks the tag's id resolver, which on a tenant's app host answers with the install's id.
 */
function measurementInstall(): MeasurementSettings
{
    config([
        'beam.analytics.providers' => [
            'gtm' => ['adapter' => GtmProvider::class, 'id' => 'GTM-INSTALL'],
            'ga4' => ['adapter' => GaProvider::class, 'id' => 'G-INSTALL1'],
        ],
    ]);

    return new class implements MeasurementSettings
    {
        public function providerIds(AnalyticsScope $scope): array
        {
            return ['gtm' => 'GTM-INSTALL', 'ga4' => 'G-INSTALL1'];
        }
    };
}

beforeEach(function () {
    (require dirname(__DIR__).'/database/migrations/shared/create_site_settings_table.php.stub')->up();
    app()->bind(AnalyticsIdResolver::class, fn () => new class implements AnalyticsIdResolver
    {
        public function idFor(string $provider): ?string
        {
            throw new RuntimeException("Measurement asked the tag's id resolver for [{$provider}].");
        }
    });
});

it('gives a tenant its own GA4 id from Site Settings, and no container', function () {
    SiteSettings::put(['ga4_measurement_id' => 'G-ACME123']);
    $settings = new TenantMeasurementSettings(measurementInstall(), app('config'));

    expect($settings->providerIds(AnalyticsScope::tenant('acme')))->toBe(['gtm' => '', 'ga4' => 'G-ACME123']);
});

it('gives a tenant with no id of its own no id at all, never the install\'s', function () {
    $settings = new TenantMeasurementSettings(measurementInstall(), app('config'));

    expect($settings->providerIds(AnalyticsScope::tenant('acme')))->toBe(['gtm' => '', 'ga4' => '']);

    Illuminate\Support\Facades\Schema::drop('site_settings');
    expect($settings->providerIds(AnalyticsScope::tenant('acme')))->toBe(['gtm' => '', 'ga4' => '']);
});

it('leaves the install\'s answer to the install\'s settings', function () {
    SiteSettings::put(['ga4_measurement_id' => 'G-ACME123']);
    $settings = new TenantMeasurementSettings(measurementInstall(), app('config'));

    expect($settings->providerIds(AnalyticsScope::install()))->toBe(['gtm' => 'GTM-INSTALL', 'ga4' => 'G-INSTALL1']);
});

it('decorates whatever MeasurementSettings the host binds', function () {
    measurementInstall();
    app()->bind(MeasurementSettings::class, ConfigMeasurementSettings::class);

    expect(app(MeasurementSettings::class))->toBeInstanceOf(TenantMeasurementSettings::class)
        ->and(app(MeasurementSettings::class)->providerIds(AnalyticsScope::install()))->toBe(['gtm' => 'GTM-INSTALL', 'ga4' => 'G-INSTALL1']);
});
