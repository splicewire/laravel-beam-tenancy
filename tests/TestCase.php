<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Database\Models\Domain;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BeamTenancyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // stancl resolves its Tenant/Domain models off config, and its published config always sets
        // them. Without them, anything touching HasDomains fatals on `new null`.
        $app['config']->set('tenancy.tenant_model', Tenant::class);
        $app['config']->set('tenancy.domain_model', Domain::class);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Mirrors create_tenants_table.php.stub. `slug` and `parent_tenant_id` are REAL columns,
        // not data-> keys — Tenant::getCustomColumns() names them — so a harness without them
        // fails any test that touches the actual Tenant rather than the TestTenant fixture.
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->string('parent_tenant_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }
}
