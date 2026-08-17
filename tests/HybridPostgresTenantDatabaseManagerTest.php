<?php

use Splicewire\Beam\Tenancy\Destinations\CustomerSuppliedDatabaseDestination;
use Splicewire\Beam\Tenancy\Destinations\GcpCloudSqlDestination;
use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseDestination;
use Splicewire\Beam\Tenancy\HybridPostgresTenantDatabaseManager;
use Splicewire\Beam\Tenancy\PostgreSQLSchemaManager;
use Splicewire\Beam\Tenancy\Tenant;

/** @return array{0: HybridPostgresTenantDatabaseManager, 1: PostgreSQLSchemaManager, 2: IsolatedDatabaseDestination, 3: CustomerSuppliedDatabaseDestination, 4: GcpCloudSqlDestination} */
function makeHybridManager(): array
{
    $schemaManager = Mockery::mock(PostgreSQLSchemaManager::class);
    $laravelCloud = Mockery::mock(IsolatedDatabaseDestination::class);
    $customerSupplied = Mockery::mock(CustomerSuppliedDatabaseDestination::class);
    $gcpCloudSql = Mockery::mock(GcpCloudSqlDestination::class);

    $manager = new HybridPostgresTenantDatabaseManager($schemaManager, $laravelCloud, $customerSupplied, $gcpCloudSql);

    return [$manager, $schemaManager, $laravelCloud, $customerSupplied, $gcpCloudSql];
}

it('delegates createDatabase to the schema manager for a non-isolated tenant, touching no destination', function () {
    [$manager, $schemaManager, $laravelCloud, $customerSupplied, $gcpCloudSql] = makeHybridManager();
    $tenant = new Tenant(['id' => 'tenant-shared']);

    $schemaManager->shouldReceive('createDatabase')->once()->with($tenant)->andReturn(true);
    $laravelCloud->shouldNotReceive('provision');
    $customerSupplied->shouldNotReceive('provision');
    $gcpCloudSql->shouldNotReceive('provision');

    expect($manager->createDatabase($tenant))->toBeTrue();
});

it('throws for a customer_supplied marker at creation time — unsupported outside the live-migration job', function () {
    [$manager, , , , $gcpCloudSql] = makeHybridManager();
    $tenant = new Tenant(['id' => 'tenant-cs']);
    $tenant->markIsolatedDatabase(true)->markIsolatedDatabaseDestination('customer_supplied');

    $gcpCloudSql->shouldNotReceive('provision');

    expect(fn () => $manager->createDatabase($tenant))
        ->toThrow(RuntimeException::class, 'unsupported');
});

it('provisions a net-new isolated tenant on gcp_cloud_sql — never laravel_cloud — and marks the destination', function () {
    // setInternal(db_password/...) round-trips through Tenant's per-key encrypted casts
    // (ticket 10) — needs a real app key, which this package's TestCase doesn't set by default
    // since nothing else in its suite touches an encrypted-cast field.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);

    [$manager, , $laravelCloud, , $gcpCloudSql] = makeHybridManager();
    $tenant = new Tenant(['id' => 'tenant-new']);
    $tenant->markIsolatedDatabase(true); // no destination marker — the real net-new case

    $connection = ['hostname' => '203.0.113.9', 'port' => 5432, 'username' => 'gcp-user', 'password' => 'gcp-pass'];
    $gcpCloudSql->shouldReceive('provision')->once()
        ->with(['name' => 'tower-tenant-new'])
        ->andReturn(['identifier' => 'gcp-id', 'database' => 'tenant_db', 'connection' => $connection]);
    $gcpCloudSql->shouldReceive('installExtensions')->once()->with($connection, 'tenant_db');
    $laravelCloud->shouldNotReceive('provision');

    $result = $manager->createDatabase($tenant);

    expect($result)->toBeTrue();
    expect($tenant->isolatedDatabaseDestination())->toBe('gcp_cloud_sql');
    expect($tenant->isolated_database_cluster_id)->toBe('gcp-id');
});

it('routes deleteDatabase teardown to the destination the tenant was actually marked with', function () {
    [$manager, , $laravelCloud, $customerSupplied, $gcpCloudSql] = makeHybridManager();

    $gcpTenant = new Tenant(['id' => 'tenant-gcp']);
    $gcpTenant->markIsolatedDatabase(true)->markIsolatedDatabaseDestination('gcp_cloud_sql');
    $gcpTenant->isolated_database_cluster_id = 'gcp-cluster-1';
    $gcpCloudSql->shouldReceive('teardown')->once()->with('gcp-cluster-1');
    $manager->deleteDatabase($gcpTenant);

    $customTenant = new Tenant(['id' => 'tenant-custom']);
    $customTenant->markIsolatedDatabase(true)->markIsolatedDatabaseDestination('customer_supplied');
    $customTenant->isolated_database_cluster_id = 'db.customer.example:5432/app';
    $customerSupplied->shouldReceive('teardown')->once()->with('db.customer.example:5432/app');
    $manager->deleteDatabase($customTenant);

    // Unmarked = predates the marker (e.g. entreport's original cutover) — still routes to
    // Laravel Cloud, the one case that fallback exists for.
    $legacyTenant = new Tenant(['id' => 'tenant-legacy']);
    $legacyTenant->markIsolatedDatabase(true);
    $legacyTenant->isolated_database_cluster_id = 'legacy-cluster-1';
    $laravelCloud->shouldReceive('teardown')->once()->with('legacy-cluster-1');
    $manager->deleteDatabase($legacyTenant);
});
