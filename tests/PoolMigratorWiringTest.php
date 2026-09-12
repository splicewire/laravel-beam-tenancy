<?php

use Illuminate\Support\Facades\Artisan;
use Rushing\PostgresRls\RowLevelSecurity\PreparationReport;
use Splicewire\Beam\Tenancy\Commands\PoolsMigrate;
use Splicewire\Beam\Tenancy\Destinations\CustomerSuppliedDatabaseDestination;
use Splicewire\Beam\Tenancy\Destinations\GcpCloudSqlDestination;
use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseDestination;
use Splicewire\Beam\Tenancy\HybridPostgresTenantDatabaseManager;
use Splicewire\Beam\Tenancy\Pools\PoolMigrator;
use Splicewire\Beam\Tenancy\PostgreSQLSchemaManager;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * pooled-storage ticket 05 — the wiring a fake PoolMigrator can prove; the real pool is the Postgres-gated class.
 */
it('joins a pooled tenant to its pool on createDatabase: ensures the pool once, points db_name at it, carries the key as a session setting', function () {
    config(['beam.tenancy.pooled.schema_prefix' => 'pool_', 'beam.tenancy.pooled.session_setting' => 'app.tenant_id']);
    $migrator = Mockery::mock(PoolMigrator::class);
    $migrator->shouldReceive('ensure')->once()->with('default')->andReturn(new PreparationReport('pool_default'));
    $schemaManager = Mockery::mock(PostgreSQLSchemaManager::class);
    $schemaManager->shouldNotReceive('createDatabase');

    $manager = new HybridPostgresTenantDatabaseManager(
        $schemaManager,
        Mockery::mock(IsolatedDatabaseDestination::class),
        Mockery::mock(CustomerSuppliedDatabaseDestination::class),
        Mockery::mock(GcpCloudSqlDestination::class),
        $migrator,
    );

    $tenant = Tenant::create(['id' => 'small', 'name' => 'Small', 'slug' => 'small']);
    $tenant->markPooled('default');
    // What stancl's CreateDatabase job writes a moment before calling the manager.
    $tenant->setInternal('db_name', 'tenant_small');

    expect($manager->createDatabase($tenant))->toBeTrue();

    $fresh = Tenant::find('small');
    expect($fresh->database()->getName())->toBe('pool_default')
        ->and($fresh->database()->tenantConfig())->toMatchArray(['session_settings' => ['app.tenant_id' => 'small']]);
});

it('migrates every known pool — each marked pool once, plus the default — through the one migrator', function () {
    config(['beam.tenancy.pooled.default_pool' => 'default']);
    Tenant::create(['id' => 'a', 'name' => 'A', 'slug' => 'a'])->markPooled('eu')->save();
    Tenant::create(['id' => 'b', 'name' => 'B', 'slug' => 'b'])->markPooled('eu')->save();
    Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c']); // schema tenant, no pool

    $migrator = Mockery::mock(PoolMigrator::class);
    $migrator->shouldReceive('schemaFor')->andReturnUsing(fn ($p) => "pool_$p");
    $migrator->shouldReceive('migrate')->once()->with('eu')->andReturn(new PreparationReport('pool_eu'));
    $migrator->shouldReceive('migrate')->once()->with('default')->andReturn(new PreparationReport('pool_default'));
    app()->instance(PoolMigrator::class, $migrator);
    Artisan::registerCommand(app(PoolsMigrate::class));

    $this->artisan('splicewire:beam:tenancy:pools:migrate')
        ->expectsOutputToContain('Pool: eu (pool_eu)')
        ->expectsOutputToContain('Pool: default (pool_default)')
        ->assertSuccessful();
});

it('refuses a pool name that is not a bare identifier — it becomes a schema name', function () {
    expect(fn () => (new PoolMigrator)->schemaFor('Bad-Name'))->toThrow(RuntimeException::class, 'schema name')
        ->and((new PoolMigrator)->schemaFor('eu_west'))->toBe('pool_eu_west');
});
