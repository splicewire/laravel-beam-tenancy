<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tenancy\Support\StanclTenancyContext;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The stancl adapter for beam core's TenancyContext port (ADR-0236 / host-binding-seam ticket 02):
 * the live-tenant half core's default deliberately answers false/null, plus the delegation that
 * retired TenancyConnections' documented duplication.
 */
class TenancyContextAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The estate's testbench rule (see EndTenancyOnTerminateTest): stancl's provider is not
        // booted here, so Tenancy must be bound singleton or every tenancy() call is a fresh
        // instance and initialize()/initialized talk past each other; the Tenant contract rides it.
        $this->app->singleton(\Stancl\Tenancy\Tenancy::class);
        $this->app->bind(\Stancl\Tenancy\Contracts\Tenant::class, fn ($app) => $app[\Stancl\Tenancy\Tenancy::class]->tenant);
    }

    public function test_the_package_binds_its_adapter(): void
    {
        $this->assertInstanceOf(StanclTenancyContext::class, $this->app->make(TenancyContext::class));
    }

    public function test_outside_tenancy_the_answers_are_central_shaped(): void
    {
        $context = $this->app->make(TenancyContext::class);

        $this->assertFalse($context->isActive());
        $this->assertNull($context->currentTenant());
        $this->assertNull($context->currentTenantId());
        // This harness DECLARES a central split: stancl's testbench config sets
        // `tenancy.database.central_connection = 'testing'` and that connection exists — so the
        // declared-and-resolvable answer is its name. Honest per ADR-0236.
        $this->assertSame('testing', $context->centralConnection());
        $this->assertTrue($context->isMultiTenant());

        // And with the declaration removed, the answers go central-shaped: null, never the
        // default connection's name.
        config()->set('tenancy.database.central_connection', null);
        $this->assertNull($context->centralConnection());
        $this->assertFalse($context->isMultiTenant());
    }

    public function test_an_initialized_tenant_is_the_live_answer(): void
    {
        $tenant = Tenant::create(['id' => 'ctx-live', 'name' => 'Ctx Live', 'slug' => 'ctx-live']);

        tenancy()->initialize($tenant);

        try {
            $context = $this->app->make(TenancyContext::class);

            $this->assertTrue($context->isActive());
            $this->assertSame('ctx-live', (string) $context->currentTenantId());
            $this->assertSame('ctx-live', (string) $context->currentTenant()?->getTenantKey());
        } finally {
            tenancy()->end();
        }

        $this->assertFalse($this->app->make(TenancyContext::class)->isActive());
    }

    public function test_a_declared_central_connection_resolves_through_port_and_helper_alike(): void
    {
        config()->set('database.connections.central_probe', ['driver' => 'sqlite', 'database' => ':memory:']);
        config()->set('tenancy.database.central_connection', 'central_probe');

        $context = $this->app->make(TenancyContext::class);

        $this->assertSame('central_probe', $context->centralConnection());
        $this->assertTrue($context->isMultiTenant());
        // TenancyConnections::central() now delegates to the port — same answer by construction.
        $this->assertSame('central_probe', TenancyConnections::central());
        // isSplit() keeps its deliberately stricter inequality variant.
        $this->assertTrue(TenancyConnections::isSplit());
    }
}
