<?php

namespace Splicewire\Beam\Tenancy\Analytics;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver;
use Splicewire\Beam\Analytics\Providers\GaProvider;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tenancy\Site\SiteSettings;
use Splicewire\Beam\Tenancy\Sitemap\HostedSiteAccess;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * Analytics ADR-0237 d4–d5 (EP-33): the tenancy layer rebinds the analytics id port for a Site Host, as it rebinds the
 * sitemap's base URL ({@see \Splicewire\Beam\Tenancy\Sitemap\TenantSitemapBaseUrlResolver}).
 *
 * On a Site Host (the request is served on the active tenant's site host, and the tenant is allowed a Hosted site) a
 * GA4 provider's id is the tenant's own measurement id from its {@see SiteSettings}, and every other provider (a
 * container) has none, so the install's container never renders there and a site with no id renders no tag. Anywhere
 * else, including the tenant's own app host, the inner resolver decides, as before.
 */
final class TenantAnalyticsIdResolver implements AnalyticsIdResolver
{
    public function __construct(private Container $container, private AnalyticsIdResolver $inner) {}

    public function idFor(string $provider): ?string
    {
        if (! $this->onSiteHost()) {
            return $this->inner->idFor($provider);
        }

        $adapter = $this->container->make('config')->get("beam.analytics.providers.{$provider}.adapter");
        if ($adapter !== GaProvider::class && ! (is_string($adapter) && is_subclass_of($adapter, GaProvider::class))) {
            return null;
        }

        return SiteSettings::current()?->ga4_measurement_id ?: null;
    }

    private function onSiteHost(): bool
    {
        $tenant = $this->container->bound(TenancyContext::class) ? $this->container->make(TenancyContext::class)->currentTenant() : null;
        if (! $tenant instanceof Tenant) {
            return false;
        }
        $siteHost = method_exists($tenant, 'siteHost') ? $tenant->siteHost() : null;
        if ($siteHost === null || ! $this->container->bound('request')) {
            return false;
        }
        $request = $this->container->make('request');
        if (! $request instanceof Request || strcasecmp($request->getHost(), $siteHost) !== 0) {
            return false;
        }

        // An absent binding is not an implicit grant: Site Hosts are reserved for Hosted tenants.
        return $this->container->bound(HostedSiteAccess::class)
            && $this->container->make(HostedSiteAccess::class)->allows($tenant);
    }
}
