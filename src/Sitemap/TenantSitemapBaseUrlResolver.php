<?php

namespace Splicewire\Beam\Tenancy\Sitemap;

use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Sitemap\Resolvers\ConfigSitemapBaseUrlResolver;
use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Tenancy;

/**
 * The multi-domain {@see SitemapBaseUrlResolver} (ADR-0166 §3). `laravel-beam-tenancy`
 * owns the domain→tenant mapping, so it binds the resolver that returns the **active
 * tenant's domain** — a beam-internal composition between two beam arms, not a satellite
 * concern.
 *
 * When a tenant context is active (`tenancy()->initialized`), the base URL is the active
 * tenant's Tenant Host ({@see Tenant::primaryHost()}) with a scheme prefixed. When tenancy
 * is ABSENT — single-tenant / central / retrofit — it delegates to the beam-sitemap
 * config-default ({@see ConfigSitemapBaseUrlResolver},
 * `config('app.url')`), honoring ADR-0151's retrofit stance that the base must not require
 * tenancy.
 *
 * Cross-tenant sitemap aggregation (a host-level index across sites) is DEFERRED to tower
 * (ADR-0166 §5) and deliberately not built here — this resolver answers for exactly the one
 * active tenant.
 */
class TenantSitemapBaseUrlResolver implements SitemapBaseUrlResolver
{
    public function __construct(
        protected Container $container,
        protected SitemapBaseUrlResolver $fallback,
    ) {}

    public function baseUrl(): string
    {
        $tenant = $this->activeTenant();

        if ($tenant === null) {
            // Tenancy absent (single-tenant / central / retrofit): the config-default.
            return $this->fallback->baseUrl();
        }

        return $this->scheme().'://'.$tenant->primaryHost();
    }

    /**
     * The active tenant, or null when no tenant context is initialized. Guards against
     * stancl's Tenancy manager being unregistered (a truly tenancy-less host) so the
     * resolver degrades to the config-default instead of throwing.
     */
    protected function activeTenant(): ?Tenant
    {
        if (! $this->container->bound(Tenancy::class)) {
            return null;
        }

        $tenancy = $this->container->make(Tenancy::class);

        if (! $tenancy->initialized) {
            return null;
        }

        $tenant = $tenancy->tenant;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * The scheme sitemap URLs hang off. Derives from the config-default's base URL
     * (`config('app.url')`) so a host that runs on http in dev stays on http; defaults to
     * https for a bare host. The tenant supplies the host, the platform supplies the scheme.
     */
    protected function scheme(): string
    {
        $scheme = parse_url($this->fallback->baseUrl(), PHP_URL_SCHEME);

        return is_string($scheme) && $scheme !== '' ? $scheme : 'https';
    }
}
