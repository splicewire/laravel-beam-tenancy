<?php

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Tenancy\Doctor\PooledTenantMarkersAudit;
use Splicewire\Beam\Tenancy\Pools\PoolRegistry;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * pooled-storage ticket 13 — which server a pool lives on, provable without Postgres.
 */
beforeEach(function () {
    config([
        'database.connections.pool_eu1' => ['driver' => 'pgsql', 'host' => '10.0.0.1', 'database' => 'pools'],
        'database.connections.not_pg' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'beam.tenancy.pooled.rls_user' => ['username' => 'beam_rls', 'password' => 'global'],
        'beam.tenancy.pooled.pools' => [
            'eu1' => ['connection' => 'pool_eu1', 'rls_user' => ['username' => 'eu1_rls', 'password' => 'own']],
            'eu2' => ['connection' => 'pool_eu1'],
            'broken' => ['connection' => 'not_pg'],
        ],
    ]);
});

it('keeps an unlisted pool on the central connection', function () {
    $registry = app(PoolRegistry::class);

    expect($registry->remoteConnectionFor('default'))->toBeNull()
        ->and($registry->connectionFor('default'))->toBe('testing');
});

it('names a listed pool\'s own server, and refuses a connection that is not pgsql', function () {
    $registry = app(PoolRegistry::class);

    expect($registry->remoteConnectionFor('eu1'))->toBe('pool_eu1')
        ->and($registry->connectionFor('eu2'))->toBe('pool_eu1');

    expect(fn () => $registry->remoteConnectionFor('broken'))->toThrow(RuntimeException::class, 'not a defined pgsql connection');
});

it('uses a pool\'s own RLS role when it has one, else the global role', function () {
    $registry = app(PoolRegistry::class);

    expect($registry->rlsUserFor('eu1'))->toBe(['username' => 'eu1_rls', 'password' => 'own'])
        ->and($registry->rlsUserFor('eu2'))->toBe(['username' => 'beam_rls', 'password' => 'global'])
        ->and($registry->rlsUserFor('default'))->toBe(['username' => 'beam_rls', 'password' => 'global']);
});

it('lists each server with the roles its pools need, once each', function () {
    config(['beam.tenancy.pooled.pools' => [
        'eu1' => ['connection' => 'pool_eu1', 'rls_user' => ['username' => 'eu1_rls', 'password' => 'own']],
        'eu2' => ['connection' => 'pool_eu1'],
    ]]);

    expect(app(PoolRegistry::class)->serversWithRoles())->toBe([
        'testing' => [['username' => 'beam_rls', 'password' => 'global']],
        'pool_eu1' => [['username' => 'eu1_rls', 'password' => 'own'], ['username' => 'beam_rls', 'password' => 'global']],
    ]);
});

it('reads the pool back out of a pool schema name', function () {
    expect(PoolRegistry::poolFromSchema('pool_eu1'))->toBe('eu1')
        ->and(PoolRegistry::poolFromSchema('tenant_acme'))->toBeNull();
});

it('fails the markers audit when a pooled tenant points at a different server than its pool', function () {
    config(['beam.tenancy.pooled.session_setting' => 'app.tenant_id', 'beam.tenancy.pooled.schema_prefix' => 'pool_']);
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme', 'slug' => 'acme']);
    $tenant->markPooled('eu2');
    $tenant->setInternal('db_session_settings', ['app.tenant_id' => 'acme']);
    $tenant->setInternal('db_name', 'pool_eu2');
    // No db_connection: it would read the central database's copy of pool_eu2, not pool_eu1's server.
    $tenant->save();

    $finding = (new PooledTenantMarkersAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Fail)
        ->and($finding->detail)->toContain("db_connection is 'central', not pool 'eu2's server 'pool_eu1'");
});

it('refuses one role configured with two passwords on the same server', function () {
    config(['beam.tenancy.pooled.pools' => [
        'eu1' => ['connection' => 'pool_eu1', 'rls_user' => ['username' => 'shared_rls', 'password' => 'one']],
        'eu2' => ['connection' => 'pool_eu1', 'rls_user' => ['username' => 'shared_rls', 'password' => 'two']],
    ]]);

    expect(fn () => app(PoolRegistry::class)->serversWithRoles())->toThrow(RuntimeException::class, 'two different passwords');
});
