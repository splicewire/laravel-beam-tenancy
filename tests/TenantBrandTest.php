<?php

use Splicewire\Beam\Brand\Brand;
use Splicewire\Beam\Brand\BrandResolver;
use Splicewire\Beam\Brand\ConfigBrandResolver;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/**
 * ADR-0234 d5 (EP-28 SD-09): on a tenant's host the brand is the Hosted site's own, through the BrandResolver port this
 * package rebinds as it does the sitemap's base URL, so the title template, the social tags and the Structured Data
 * name the tenant. The name only for now: the logo comes with the Hosted site's Site Settings (EP-33), so until then
 * the rest of the brand is the install's.
 */
beforeEach(function () {
    // A host binds the config brand through laravel-beam's provider, which this harness does not boot.
    app()->bind(BrandResolver::class, ConfigBrandResolver::class);
    config(['beam.brand' => ['name' => 'Splicewire', 'logo' => '/brand.svg', 'title_template' => ':title | :name']]);
    app()->instance(Tenancy::class, new Tenancy);
});

/** A tenant made current as stancl does on initialize: the manager flags it and the container holds it. */
function brandTenant(?string $name): Tenancy
{
    $tenant = tap(new Tenant(['id' => 'acme', 'name' => $name, 'slug' => 'acme']))->setRelation('domains', collect());
    app()->instance(TenantContract::class, $tenant);
    $tenancy = app(Tenancy::class);
    $tenancy->initialized = true;
    $tenancy->tenant = $tenant;

    return $tenancy;
}

it('names the install where no tenant is active', function () {
    expect(Brand::for()->name)->toBe('Splicewire');
});

it("names the tenant on its own host and keeps the rest of the install's brand until Site Settings exist", function () {
    $tenancy = brandTenant('Acme Bakery');

    $brand = Brand::for();

    expect($brand->name)->toBe('Acme Bakery')
        ->and($brand->titleTemplate)->toBe(':title | :name')
        ->and($brand->logo)->toBe('/brand.svg')
        ->and($brand->passkeyCopy)->toBe("Passkeys secure your Acme Bakery login. They're tied to your account.");

    $tenancy->initialized = false;
    expect(Brand::for()->name)->toBe('Splicewire');
});

it('keeps the install name for a tenant with none', function () {
    brandTenant(null);

    expect(Brand::for()->name)->toBe('Splicewire');
});
