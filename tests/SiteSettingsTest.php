<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Analytics\Contracts\AnalyticsIdResolver;
use Splicewire\Beam\Analytics\Providers\GaProvider;
use Splicewire\Beam\Analytics\Providers\GtmProvider;
use Splicewire\Beam\Brand\BrandData;
use Splicewire\Beam\Brand\BrandResolver;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tenancy\Analytics\TenantAnalyticsIdResolver;
use Splicewire\Beam\Tenancy\Brand\TenantBrandResolver;
use Splicewire\Beam\Tenancy\Site\SiteSettings;
use Splicewire\Beam\Tenancy\Sitemap\HostedSiteAccess;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * EP-33 slice C1 (analytics ADR-0237 d4–d6; plan: launch EP33C-SLICE-PLAN.md R1–R4). A Hosted site's Site Settings, its
 * brand (name, logo) and its GA4 measurement id, are its tenant's own, held in the tenant's schema; the Site Host renders
 * that brand through the BrandResolver port and measures with that id through the AnalyticsIdResolver port, and the
 * install's container never renders there.
 */
function siteTenant(?string $siteHost = 'acme.site.splicewire.test'): Tenant
{
    return tap(new class(['id' => 'acme', 'name' => 'Acme Inc', 'slug' => 'acme']) extends Tenant
    {
        public ?string $site = null;

        public function siteHost(): ?string
        {
            return $this->site;
        }
    }, fn ($tenant) => $tenant->site = $siteHost)->setRelation('domains', collect());
}

function activateTenant(?Tenant $tenant, bool $hosted = true): void
{
    app()->instance(TenancyContext::class, new class($tenant) implements TenancyContext
    {
        public function __construct(private ?Tenant $tenant) {}

        public function isMultiTenant(): bool
        {
            return true;
        }

        public function isActive(): bool
        {
            return $this->tenant !== null;
        }

        public function currentTenant(): ?object
        {
            return $this->tenant;
        }

        public function currentTenantId(): int|string|null
        {
            return $this->tenant?->getTenantKey();
        }

        public function centralConnection(): ?string
        {
            return null;
        }
    });
    app()->instance(HostedSiteAccess::class, new class($hosted) implements HostedSiteAccess
    {
        public function __construct(private bool $hosted) {}

        public function allows(mixed $tenant): bool
        {
            return $this->hosted;
        }
    });
}

function requestOn(string $host): Request
{
    $request = Request::create('https://'.$host.'/');
    app()->instance('request', $request);

    return $request;
}

function installIds(): AnalyticsIdResolver
{
    config([
        'beam.analytics.providers.gtm' => ['adapter' => GtmProvider::class, 'id' => 'GTM-INSTALL'],
        'beam.analytics.providers.ga4' => ['adapter' => GaProvider::class, 'id' => 'G-INSTALL1'],
    ]);

    return new class implements AnalyticsIdResolver
    {
        public function idFor(string $provider): ?string
        {
            return ['gtm' => 'GTM-INSTALL', 'ga4' => 'G-INSTALL1'][$provider] ?? null;
        }
    };
}

beforeEach(function () {
    (require dirname(__DIR__).'/database/migrations/shared/create_site_settings_table.php.stub')->up();
});

// Landing order (orch-lead 13:29Z): the decorators bind the moment a host takes this package, before that host has run
// its next migrate, so a schema with no site_settings table answers exactly as before Site Settings existed.
it('answers as before Site Settings existed while the table is not yet migrated', function () {
    Illuminate\Support\Facades\Schema::drop('site_settings');
    activateTenant(siteTenant());
    $install = new class implements BrandResolver
    {
        public function for(?Request $request = null): BrandData
        {
            return new BrandData(name: 'Splicewire', logo: '/install-logo.svg', titleTemplate: '%s · Splicewire', legalEntity: 'Splicewire',
                passkeyCopy: 'Sign in to Splicewire', contact: [], home: '/', tagline: null);
        }
    };
    $brand = (new TenantBrandResolver(app(TenancyContext::class), $install))->for();
    $ids = new TenantAnalyticsIdResolver(app(), installIds());

    expect(SiteSettings::current())->toBeNull()
        ->and($brand->name)->toBe('Acme Inc')
        ->and($brand->logo)->toBe('/install-logo.svg');

    requestOn('acme.app.splicewire.test');
    expect($ids->idFor('gtm'))->toBe('GTM-INSTALL');

    requestOn('acme.site.splicewire.test');
    expect($ids->idFor('ga4'))->toBeNull()
        ->and($ids->idFor('gtm'))->toBeNull();
});

it('accepts a GA4 measurement id and refuses a container id or anything else', function () {
    expect(SiteSettings::isMeasurementId('G-ABC1234'))->toBeTrue();
    foreach (['GTM-ABC123', 'UA-12345-1', 'G-', 'g-abc1234', 'G-ABC 123', ''] as $id) {
        expect(SiteSettings::isMeasurementId($id))->toBeFalse();
    }
    expect(fn () => SiteSettings::query()->create(['ga4_measurement_id' => 'GTM-ABC123']))->toThrow(InvalidArgumentException::class);
});

it('holds one row per schema, the tenant\'s brand and measurement id', function () {
    SiteSettings::put(['brand_name' => 'Acme Studio', 'logo' => 'https://cdn.example/acme.svg', 'ga4_measurement_id' => 'G-ACME123']);
    SiteSettings::put(['brand_name' => 'Acme Studios']);

    expect(SiteSettings::query()->count())->toBe(1)
        ->and(SiteSettings::current()?->brand_name)->toBe('Acme Studios')
        ->and(SiteSettings::current()?->ga4_measurement_id)->toBe('G-ACME123');
});

it('gives the tenant\'s Site Settings brand to the BrandResolver port', function () {
    activateTenant(siteTenant());
    $install = new class implements BrandResolver
    {
        public function for(?Request $request = null): BrandData
        {
            return new BrandData(name: 'Splicewire', logo: '/install-logo.svg', titleTemplate: '%s · Splicewire', legalEntity: 'Splicewire',
                passkeyCopy: 'Sign in to Splicewire', contact: [], home: '/', tagline: null);
        }
    };
    $resolver = new TenantBrandResolver(app(TenancyContext::class), $install);

    expect($resolver->for()->name)->toBe('Acme Inc')
        ->and($resolver->for()->logo)->toBe('/install-logo.svg');

    SiteSettings::put(['brand_name' => 'Acme Studio', 'logo' => 'https://cdn.example/acme.svg']);

    expect($resolver->for()->name)->toBe('Acme Studio')
        ->and($resolver->for()->logo)->toBe('https://cdn.example/acme.svg');
});

it('measures a Site Host with the tenant\'s GA4 id and never renders the install\'s container there', function () {
    activateTenant(siteTenant());
    SiteSettings::put(['ga4_measurement_id' => 'G-ACME123']);
    $resolver = new TenantAnalyticsIdResolver(app(), installIds());

    requestOn('acme.site.splicewire.test');
    expect($resolver->idFor('ga4'))->toBe('G-ACME123')
        ->and($resolver->idFor('gtm'))->toBeNull();
});

it('keeps the install\'s ids off the Site Host, and renders no tag on a Site Host without an id', function () {
    activateTenant(siteTenant());
    $resolver = new TenantAnalyticsIdResolver(app(), installIds());

    requestOn('acme.app.splicewire.test');
    expect($resolver->idFor('ga4'))->toBe('G-INSTALL1')
        ->and($resolver->idFor('gtm'))->toBe('GTM-INSTALL');

    requestOn('acme.site.splicewire.test');
    expect($resolver->idFor('ga4'))->toBeNull()
        ->and($resolver->idFor('gtm'))->toBeNull();
});

it('treats a site host as a Site Host only when the tenant is allowed a Hosted site', function () {
    activateTenant(siteTenant(), hosted: false);
    SiteSettings::put(['ga4_measurement_id' => 'G-ACME123']);
    $resolver = new TenantAnalyticsIdResolver(app(), installIds());

    requestOn('acme.site.splicewire.test');
    expect($resolver->idFor('ga4'))->toBe('G-INSTALL1');
});

it('rebinds the analytics id port for every resolution once analytics binds it', function () {
    $this->app->register(Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider::class);

    expect(app(AnalyticsIdResolver::class))->toBeInstanceOf(TenantAnalyticsIdResolver::class);
});

it('never renders the install\'s container on a Site Host, and renders the tenant\'s GA4 tag there (an-0237 d5)', function () {
    $this->app->register(Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider::class);
    config([
        'app.env' => 'production',
        'beam.analytics.enabled_envs' => ['production'],
        'beam.analytics.consent.enabled' => false,
        'beam.analytics.providers' => [
            'gtm' => ['adapter' => GtmProvider::class, 'id' => 'GTM-INSTALL'],
            'ga4' => ['adapter' => GaProvider::class, 'id' => null],
        ],
    ]);
    activateTenant(siteTenant());
    SiteSettings::put(['ga4_measurement_id' => 'G-ACME123']);
    $head = fn (): string => app(Splicewire\Beam\Analytics\AnalyticsManager::class)->head();

    requestOn('acme.app.splicewire.test');
    expect($head())->toContain('GTM-INSTALL');

    requestOn('acme.site.splicewire.test');
    expect($head())->not->toContain('GTM-INSTALL')
        ->and($head())->toContain('G-ACME123');
});

// an-0237 d6 (orch-lead 13:30Z): a tenant's search_path is '<tenant>,public', so a host that has migrated central but not its
// tenants has a public site_settings that an unqualified query would reach. Site Settings read and write the CURRENT schema's
// own table only: until the tenant's is migrated they read nothing and refuse to save, and public is never written.
it('reads and writes only the current schema\'s own table, never public\'s, on PostgreSQL', function () {
    $database = getenv('EP33C_PGSQL_TEST_DATABASE');

    if (! is_string($database) || ! preg_match('/^test_[a-z0-9_]{1,54}$/', $database)) {
        test()->markTestSkipped('Set EP33C_PGSQL_TEST_DATABASE to an isolated test_* database.');
    }

    $connect = function (string $searchPath) use ($database): void {
        config(['database.connections.pgsql' => [
            'driver' => 'pgsql', 'host' => getenv('EP33C_PGSQL_HOST') ?: '127.0.0.1', 'port' => getenv('EP33C_PGSQL_PORT') ?: '5432',
            'database' => $database, 'username' => getenv('EP33C_PGSQL_USERNAME') ?: 'postgres', 'password' => getenv('EP33C_PGSQL_PASSWORD') ?: '',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => $searchPath, 'sslmode' => 'prefer',
        ]]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
    };
    $migration = require dirname(__DIR__).'/database/migrations/shared/create_site_settings_table.php.stub';

    // The connection is guarded by the test_* name above; all DDL below is confined to it.
    $connect('public');
    expect(DB::connection()->getDatabaseName())->toBe($database);
    DB::statement('DROP SCHEMA IF EXISTS tenant_ep33c CASCADE');
    DB::statement('DROP TABLE IF EXISTS public.site_settings');
    DB::statement('CREATE SCHEMA tenant_ep33c');
    $migration->up();

    $connect('tenant_ep33c,public');
    expect(SiteSettings::current())->toBeNull()
        ->and(fn () => SiteSettings::put(['ga4_measurement_id' => 'G-TENANT1']))->toThrow(Splicewire\Beam\Tenancy\Site\SiteSettingsNotMigrated::class, 'not migrated');
    expect(DB::table('public.site_settings')->count())->toBe(0);

    DB::statement('CREATE TABLE tenant_ep33c.site_settings (LIKE public.site_settings INCLUDING ALL)');
    SiteSettings::put(['ga4_measurement_id' => 'G-TENANT1']);

    expect(SiteSettings::current()?->ga4_measurement_id)->toBe('G-TENANT1')
        ->and(DB::table('tenant_ep33c.site_settings')->count())->toBe(1)
        ->and(DB::table('public.site_settings')->count())->toBe(0);

    DB::statement('DROP SCHEMA tenant_ep33c CASCADE');
    DB::statement('DROP TABLE public.site_settings');
});
