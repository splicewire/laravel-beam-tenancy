<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Database\Models\Domain;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // Testbench does NOT auto-discover — a package harness boots exactly what this method
            // names, while `src/` freely imports anything it can autoload. This package reaches
            // `spatie/laravel-data` (see `src/Data/TenantMemberRoleInputData.php`) without ever
            // booting its provider, so before this line `config('data')` was NULL inside this suite
            // and `TenantMemberRoleInputData::validateAndCreate()` died with
            // `ErrorException: Trying to access array offset on null` — a FATAL, not a failure.
            // Measured here before the fix; same omission and mechanism as `splicewire/tower`.
            // api-surface-coherence tickets 84 / 85.
            LaravelDataServiceProvider::class,

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

        // Booting LaravelDataServiceProvider alone would be a FALSE GREEN. The package ships
        // `name_mapping_strategy.input => null`, but the only host that runs this code
        // (`~/Herd/splicewire-app/config/data.php`) sets it to CamelCaseMapper — the ONLY semantic
        // delta between the two files. A DTO hydrates fine under testbench defaults and can still
        // stop mapping under the host's mapper, so the harness mirrors the host, not the default.
        $app['config']->set('data.name_mapping_strategy.input', CamelCaseMapper::class);

        // Structure caching points at `app_path('Data')` by default, which does not exist here, and
        // a cached reflection analysis carried across runs is exactly what a harness should not have.
        $app['config']->set('data.structure_caching.enabled', false);
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
