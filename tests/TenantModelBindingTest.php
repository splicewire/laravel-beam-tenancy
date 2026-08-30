<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;

/**
 * `tenancy.tenant_model` is seated on THIS package's Tenant unless the host chose one.
 *
 * ⚠️ Regression cover for a defect the package suite could not previously see. beam-tenancy
 * publishes a `tenants` schema with a NOT NULL `name` and a unique `slug`, and only its own Tenant
 * lists those in `getCustomColumns()`. Stancl's model lists `['id']`, so `HasDataColumn` routes
 * every other attribute into the `data` JSON and the NOT NULL column is never written — the
 * package's own `DemoTenantSeeder` failing against the package's own migration.
 *
 * It stayed invisible because {@see \Splicewire\Beam\Tenancy\Testing\InteractsWithTenancy} PINS the
 * model for tests, so the harness booted what no real host did. These tests deliberately do not use
 * that helper: they exercise the binding itself.
 *
 * Measured 2026-08-30 at `~/Herd/tower`, where it had failed on every seed run behind
 * `splicewire:beam:seed`'s then-unconditional exit 0.
 */
class TenantModelBindingTest extends TestCase
{
    /**
     * ⚠️ UNDO the harness pin, which is the only way this file can test anything.
     *
     * `TestCase::defineEnvironment()` sets `tenancy.tenant_model` to beam's Tenant for every test in
     * this package — a SECOND pin beside `InteractsWithTenancy`. `defineEnvironment` runs before the
     * providers boot, so putting stancl's model back here reproduces a real host that never
     * published `config/tenancy.php`, and `packageBooted()` then has to do the work.
     *
     * Without this override every assertion below passes against a provider that does nothing —
     * verified, not assumed: the first version of this file was green against the pre-change
     * provider.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tenancy.tenant_model', StanclTenant::class);
    }

    public function test_it_seats_its_own_tenant_when_the_host_expressed_no_preference(): void
    {
        // The harness handed boot stancl's model (see defineEnvironment); the provider replaced it.
        $this->assertSame(Tenant::class, config('tenancy.tenant_model'));
    }

    public function test_an_explicit_host_choice_is_never_overridden(): void
    {
        // Any value that is not stancl's own default means the host chose — last writer wins.
        config(['tenancy.tenant_model' => HostOwnTenant::class]);

        $this->app->register(\Splicewire\Beam\Tenancy\BeamTenancyServiceProvider::class, force: true);

        $this->assertSame(HostOwnTenant::class, config('tenancy.tenant_model'));
    }

    public function test_the_binding_can_be_switched_off(): void
    {
        config([
            'beam.tenancy.bind_tenant_model' => false,
            'tenancy.tenant_model' => StanclTenant::class,
        ]);

        $this->app->register(\Splicewire\Beam\Tenancy\BeamTenancyServiceProvider::class, force: true);

        $this->assertSame(StanclTenant::class, config('tenancy.tenant_model'));
    }

    public function test_the_seated_model_can_key_the_schema_this_package_publishes(): void
    {
        // The actual point: `name` and `slug` are NOT NULL / unique real columns in
        // create_tenants_table.php.stub, so they must be custom columns, not `data` JSON keys.
        $columns = config('tenancy.tenant_model')::getCustomColumns();

        $this->assertContains('name', $columns);
        $this->assertContains('slug', $columns);
        $this->assertContains('parent_tenant_id', $columns);
    }

    public function test_stancls_own_model_cannot_key_that_schema(): void
    {
        // The control. If this ever starts passing, stancl changed and the binding may be moot.
        $this->assertSame(['id'], StanclTenant::getCustomColumns());
    }
}

/** A host's own substituted Tenant — stands in for "the host chose". */
class HostOwnTenant extends Tenant {}
