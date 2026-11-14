<?php

namespace Splicewire\Beam\Tenancy\Tests\Localization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\Localization\PreferredLocaleSource;
use Splicewire\Beam\Localization\Contracts\TenantLocaleSource;
use Splicewire\Beam\Localization\DeliveryRecipient;
use Splicewire\Beam\Localization\LocaleResolver;
use Splicewire\Beam\Localization\LocaleScope;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tenancy\Localization\TenantDefaultLocaleSource;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\TestCase;

/** The central identity a host owns (the harness's uuid `users` table), with the central-only preference column. */
class TenancyCentralUser extends Model
{
    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

/**
 * localization 36 (ruling 03 owner resolution (b); §2 source 4 and §3 delivery source 2): the workspace's default
 * language, from the existing tenants.data JSON, is beam's tenant slot. A delivery about a workspace takes THAT
 * workspace's default, never the sender's tenant; a tenant-side notifiable reads its CENTRAL preference.
 */
class TenantDefaultLocaleTest extends TestCase
{
    private ?Tenant $current = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['beam.localization.supported' => ['en', 'es', 'de', 'fr'], 'app.locale' => 'en']);
        // B12-3: this harness boots tenancy and its listed providers, not beam-core's or accounts', so the resolver's other
        // ports are composed HERE, test-only, exactly as production binds them: the person slot is accounts' adapter
        // (BeamAccountsServiceProvider) and the brand slot beam's config adapter (BeamServiceProvider). No production change.
        $this->app->bind(\Splicewire\Beam\Localization\Contracts\PersonLocaleSource::class, PreferredLocaleSource::class);
        $this->app->bind(\Splicewire\Beam\Localization\Contracts\BrandLocaleSource::class, \Splicewire\Beam\Localization\Sources\ConfigBrandLocaleSource::class);
        $this->app->bind(TenancyContext::class, fn () => new class($this) implements TenancyContext
        {
            public function __construct(private TenantDefaultLocaleTest $test) {}

            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return $this->test->currentTenant() !== null;
            }

            public function currentTenant(): ?object
            {
                return $this->test->currentTenant();
            }

            public function currentTenantId(): int|string|null
            {
                return $this->test->currentTenant()?->getTenantKey();
            }

            public function centralConnection(): ?string
            {
                return 'testing';
            }
        });
    }

    public function currentTenant(): ?Tenant
    {
        return $this->current;
    }

    private function tenant(?string $default): Tenant
    {
        $tenant = new Tenant(['id' => 'ws-'.Str::lower(Str::random(6)), 'name' => 'Acme']);
        $tenant->setDefaultLocale($default);

        return $tenant;
    }

    public function test_tenancy_owns_the_tenant_slot(): void
    {
        $this->assertInstanceOf(TenantDefaultLocaleSource::class, app(TenantLocaleSource::class));
    }

    public function test_the_default_lives_in_data_json_with_no_column_and_only_a_supported_tag_is_stored(): void
    {
        $this->assertNotContains('default_locale', Tenant::getCustomColumns(), 'no real tenants column (owner resolution (b))');

        $tenant = $this->tenant('es');
        $this->assertSame('es', $tenant->getAttribute('default_locale'), 'a virtual attribute: it is encoded into data on save');
        $this->assertSame('es', $tenant->defaultLocale());

        // A stored value that is no longer supported reads as automatic.
        config(['beam.localization.supported' => ['en']]);
        $this->assertNull($tenant->defaultLocale());
    }

    public function test_an_unsupported_default_is_refused_and_null_clears(): void
    {
        $tenant = $this->tenant('de');
        $this->assertSame('de', $tenant->defaultLocale());

        $tenant->setDefaultLocale(null);
        $this->assertNull($tenant->defaultLocale());

        $this->expectException(\InvalidArgumentException::class);
        $tenant->setDefaultLocale('xx');
    }

    public function test_a_request_takes_the_initialized_tenants_default(): void
    {
        $this->current = $this->tenant('es');
        $resolution = app(LocaleResolver::class)->forRequest(Request::create('/'));

        $this->assertSame('es', $resolution->locale);
        $this->assertSame('tenant', $resolution->source);
    }

    public function test_a_delivery_about_a_workspace_takes_that_workspace_never_the_senders_tenant(): void
    {
        $this->current = $this->tenant('es');
        $source = app(TenantLocaleSource::class);

        $this->assertSame('de', $source->locale(null, new DeliveryRecipient(null, $this->tenant('de'))));
        $this->assertSame('es', $source->locale(null, new DeliveryRecipient(null)), 'no workspace named: the delivery context');
    }

    public function test_a_spanish_sender_does_not_make_an_unknown_english_workspace_invite_spanish(): void
    {
        $this->current = $this->tenant('es');
        $english = $this->tenant('en');

        $seen = LocaleScope::run('es', fn () => app(LocaleResolver::class)->deliver(
            new DeliveryRecipient(null, $english),
            fn (string $locale): string => $locale.'/'.app()->getLocale(),
        ));

        $this->assertSame('en/en', $seen);
    }

    public function test_a_tenant_side_notifiable_reads_its_central_preference(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('preferred_locale', 35)->nullable());
        config(['auth.providers.users.model' => TenancyCentralUser::class]);
        $central = TenancyCentralUser::query()->create(['id' => (string) Str::uuid(), 'email' => 'ana@example.test', 'name' => 'Ana', 'preferred_locale' => 'fr']);

        $replica = new TenantUser;
        $replica->setRawAttributes(['id' => $central->getKey()]);

        $this->assertSame('fr', (new PreferredLocaleSource)->locale(null, $replica), 'the replica carries no preference; the central identity does');
        $this->assertSame('fr', (new PreferredLocaleSource)->locale(null, new DeliveryRecipient($replica, $this->tenant('de'))), 'the person outranks the workspace');
    }
}
