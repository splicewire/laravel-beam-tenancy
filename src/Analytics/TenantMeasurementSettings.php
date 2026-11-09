<?php

namespace Splicewire\Beam\Tenancy\Analytics;

use Illuminate\Contracts\Config\Repository;
use Splicewire\Beam\Analytics\Contracts\MeasurementSettings;
use Splicewire\Beam\Analytics\Providers\GaProvider;
use Splicewire\Beam\Analytics\Scope\AnalyticsScope;
use Splicewire\Beam\Tenancy\Site\SiteSettings;

/**
 * analytics-area 05: what the Measurement area shows a tenant is the tenant's own measurement setup, read from its Site
 * Settings in its own schema. A GA4 provider answers with the tenant's GA4 measurement id; every other provider answers
 * with no id, because a Hosted site measures with its own GA4 id and never with the install's container (ADR-0237 d5).
 *
 * It never asks the tag's {@see \Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver}: on a tenant's app host that port
 * answers with the install's id, which a tenant must never be shown. Install scope is the install's own answer, so it is
 * left to whatever settings the host binds.
 */
final class TenantMeasurementSettings implements MeasurementSettings
{
    public function __construct(
        private readonly MeasurementSettings $install,
        private readonly Repository $config,
    ) {}

    public function providerIds(AnalyticsScope $scope): array
    {
        if ($scope->isInstall()) {
            return $this->install->providerIds($scope);
        }

        $ga4 = trim((string) (SiteSettings::current()?->ga4_measurement_id ?? ''));
        $ids = [];

        foreach ((array) $this->config->get('beam.analytics.providers', []) as $name => $provider) {
            $adapter = $provider['adapter'] ?? null;
            $ids[(string) $name] = is_string($adapter) && is_a($adapter, GaProvider::class, true) ? $ga4 : '';
        }

        return $ids;
    }
}
