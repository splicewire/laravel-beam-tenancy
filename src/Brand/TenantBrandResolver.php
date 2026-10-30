<?php

namespace Splicewire\Beam\Tenancy\Brand;

use Illuminate\Http\Request;
use Splicewire\Beam\Brand\BrandData;
use Splicewire\Beam\Brand\BrandResolver;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tenancy\Site\SiteSettings;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * On a tenant's host the brand is the Hosted site's own (beam-ux ADR-0234 d5, EP-28 SD-09). This package owns the
 * domain→tenant mapping, so it decorates whichever {@see BrandResolver} the host binds, as it rebinds the sitemap's
 * base URL: the active tenant's name replaces the install's, and the title template, the social tags and the
 * Structured Data read it from here.
 *
 * Its Site Settings (analytics ADR-0237 d6, EP-33) supply the brand: their name, else the tenant's name, and their logo,
 * else the install's. The rest of the brand stays the install's.
 */
final class TenantBrandResolver implements BrandResolver
{
    public function __construct(private TenancyContext $tenancy, private BrandResolver $inner) {}

    public function for(?Request $request = null): BrandData
    {
        $brand = $this->inner->for($request);
        $tenant = $this->tenancy->currentTenant();
        if (! $tenant instanceof Tenant) {
            return $brand;
        }

        $settings = SiteSettings::current();
        $name = trim((string) ($settings?->brand_name ?: (is_string($tenant->name) ? $tenant->name : '')));
        $name = $name === '' ? $brand->name : $name;
        $logo = $settings?->logo ?: $brand->logo;

        if ($name === $brand->name && $logo === $brand->logo) {
            return $brand;
        }

        return new BrandData(
            name: $name,
            logo: $logo,
            titleTemplate: $brand->titleTemplate,
            legalEntity: $brand->legalEntity,
            // The default copy names the brand; a declared one that doesn't is kept as written.
            passkeyCopy: str_replace($brand->name, $name, $brand->passkeyCopy),
            contact: $brand->contact,
            home: $brand->home,
            tagline: $brand->tagline,
        );
    }
}
