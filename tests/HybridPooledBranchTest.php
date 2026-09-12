<?php

use Splicewire\Beam\Tenancy\Destinations\CustomerSuppliedDatabaseDestination;
use Splicewire\Beam\Tenancy\Destinations\GcpCloudSqlDestination;
use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseDestination;
use Splicewire\Beam\Tenancy\HybridPostgresTenantDatabaseManager;
use Splicewire\Beam\Tenancy\Pools\PoolMigrator;
use Splicewire\Beam\Tenancy\PostgreSQLSchemaManager;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * pooled-storage ticket 05 — the hybrid manager's pooled branch, the parts a fake schema manager can prove.
 * The end-to-end (real pool, real policy) is the Postgres-gated class beside this.
 */
function makePooledHybrid(): array
{
    $schemaManager = Mockery::mock(PostgreSQLSchemaManager::class);
    $manager = new HybridPostgresTenantDatabaseManager(
        $schemaManager,
        Mockery::mock(IsolatedDatabaseDestination::class),
        Mockery::mock(CustomerSuppliedDatabaseDestination::class),
        Mockery::mock(GcpCloudSqlDestination::class),
        Mockery::mock(PoolMigrator::class),
    );

    return [$manager, $schemaManager];
}

it('names the pool schema off the configured prefix', function () {
    config(['beam.tenancy.pooled.schema_prefix' => 'pool_']);
    $tenant = (new Tenant(['id' => 'small']))->markPooled('default');

    expect($tenant->poolSchema())->toBe('pool_default')
        ->and((new Tenant(['id' => 'plain']))->poolSchema())->toBeNull();

    // One grammar for every writer: the marker refuses a name that cannot be a schema.
    expect(fn () => (new Tenant(['id' => 'x']))->markPooled('Bad-Name'))->toThrow(RuntimeException::class, 'schema name');
});

it('checks a pool name against the real schema catalogue, not the isolated-database shortcut', function () {
    config(['beam.tenancy.pooled.schema_prefix' => 'pool_', 'tenancy.database.prefix' => 'tenant_']);
    [$manager, $schemaManager] = makePooledHybrid();

    $schemaManager->shouldReceive('databaseExists')->once()->with('pool_default')->andReturn(false);

    expect($manager->databaseExists('pool_default'))->toBeFalse()
        // The isolated-database shortcut still answers true for an unprefixed name.
        ->and($manager->databaseExists('production'))->toBeTrue();
});

it('swaps a pooled connection onto the non-owner role, keeps the session settings, and splices the pool into search_path', function () {
    config([
        'beam.tenancy.pooled.rls_user' => ['username' => 'beam_rls', 'password' => 'secret'],
        'database.connections.pgsql.host' => '127.0.0.1',
    ]);
    [$manager, $schemaManager] = makePooledHybrid();
    $base = ['host' => '127.0.0.1', 'username' => 'owner', 'password' => 'owner-pw', 'session_settings' => ['app.tenant_id' => 'small']];

    $schemaManager->shouldReceive('makeConnectionConfig')->once()
        ->withArgs(fn (array $config, string $name) => $config['username'] === 'beam_rls'
            && $config['password'] === 'secret'
            && $config['session_settings'] === ['app.tenant_id' => 'small']
            && $name === 'pool_default')
        ->andReturnUsing(fn (array $config, string $name) => $config + ['search_path' => "$name,public"]);

    $config = $manager->makeConnectionConfig($base, 'pool_default');

    expect($config['username'])->toBe('beam_rls')
        ->and($config['search_path'])->toBe('pool_default,public')
        ->and($config['session_settings'])->toBe(['app.tenant_id' => 'small']);
});

it('refuses to connect a pooled tenant as the owner when no non-owner role is configured', function () {
    config(['beam.tenancy.pooled.rls_user' => ['username' => null, 'password' => null]]);
    [$manager] = makePooledHybrid();

    expect(fn () => $manager->makeConnectionConfig(['host' => '127.0.0.1', 'session_settings' => ['app.tenant_id' => 'x']], 'pool_default'))
        ->toThrow(RuntimeException::class, 'non-owner Postgres role');
});

it('leaves a schema tenant\'s connection exactly as before — no settings key, no role swap', function () {
    config(['database.connections.pgsql.host' => '127.0.0.1']);
    [$manager, $schemaManager] = makePooledHybrid();
    $base = ['host' => '127.0.0.1', 'username' => 'owner'];

    $schemaManager->shouldReceive('makeConnectionConfig')->once()->with($base, 'tenant_acme')->andReturn($base + ['search_path' => 'tenant_acme,public']);

    expect($manager->makeConnectionConfig($base, 'tenant_acme')['username'])->toBe('owner');
});
