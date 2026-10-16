<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Rushing\AuthVault\Contracts\VaultContextGuard;
use Rushing\AuthVault\Exceptions\VaultContextException;
use Splicewire\Beam\Tenancy\Contracts\TenantDnsProvider;
use Splicewire\Beam\Tenancy\Contracts\TenantEdgeProvider;
use Splicewire\Beam\Tenancy\Domains\NullDomainProvider;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Vault\TenancyVaultContextGuard;

/**
 * The remaining ticket-02 defaults at their interfaces: Dns/Edge resolve to the truthful Null
 * provider (before this, an unbound host crashed inside the FIRST provisioning job), and the vault
 * partition guard binds where auth-vault is loaded and fails closed outside tenancy.
 */
class HostBindingDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The estate's testbench rule (see EndTenancyOnTerminateTest): bind Tenancy singleton so
        // the guard's tenancy()->initialized reads the same instance the test initializes.
        $this->app->singleton(\Stancl\Tenancy\Tenancy::class);
        $this->app->bind(\Stancl\Tenancy\Contracts\Tenant::class, fn ($app) => $app[\Stancl\Tenancy\Tenancy::class]->tenant);
    }

    public function test_dns_and_edge_default_to_the_null_provider(): void
    {
        $this->assertInstanceOf(NullDomainProvider::class, $this->app->make(TenantDnsProvider::class));
        $this->assertInstanceOf(NullDomainProvider::class, $this->app->make(TenantEdgeProvider::class));
    }

    public function test_a_host_binding_overrides_the_domain_defaults(): void
    {
        $custom = new class extends NullDomainProvider {};
        $this->app->bind(TenantDnsProvider::class, fn () => $custom);

        $this->assertSame($custom, $this->app->make(TenantDnsProvider::class));
        // The untouched contract keeps the default.
        $this->assertInstanceOf(NullDomainProvider::class, $this->app->make(TenantEdgeProvider::class));
    }

    public function test_the_vault_guard_binds_and_fails_closed_outside_tenancy(): void
    {
        if (! interface_exists(VaultContextGuard::class)) {
            $this->markTestSkipped('rushing/laravel-auth-vault not installed (require-dev) — run composer update');
        }

        $guard = $this->app->make(VaultContextGuard::class);
        $this->assertInstanceOf(TenancyVaultContextGuard::class, $guard);

        $this->expectException(VaultContextException::class);
        $guard->assert('conduit-1', 'read');
    }

    public function test_the_vault_guard_admits_an_initialized_tenant(): void
    {
        if (! interface_exists(VaultContextGuard::class)) {
            $this->markTestSkipped('rushing/laravel-auth-vault not installed (require-dev) — run composer update');
        }

        $tenant = Tenant::create(['id' => 'vault-ok', 'name' => 'Vault Ok', 'slug' => 'vault-ok']);
        tenancy()->initialize($tenant);

        try {
            $this->app->make(VaultContextGuard::class)->assert('conduit-1', 'read');
            $this->assertTrue(true); // no exception = the partition precondition held
        } finally {
            tenancy()->end();
        }
    }
}
