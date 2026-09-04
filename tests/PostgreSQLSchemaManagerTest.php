<?php

use Splicewire\Beam\Tenancy\PostgreSQLSchemaManager;

/**
 * The one line in this estate that writes a tenant `search_path`.
 *
 * It had **zero** unit coverage: the only test naming this class
 * ({@see HybridPostgresTenantDatabaseManagerTest}) Mockery-mocks it, so the real
 * `makeConnectionConfig()` was executed by nothing. It is a pure function of its arguments — no
 * connection, no container — so the absence was never a difficulty, only an oversight.
 *
 * Worth covering on its own merits, and doubly so because the *ordering* it produces is what makes a
 * leaked tenancy frame silent rather than loud: with the tenant schema first, a stale frame resolves
 * every query against the previous tenant's tables instead of erroring on a missing one. That is the
 * failure {@see \Splicewire\Beam\Tenancy\Http\Middleware\EndTenancyOnTerminate} exists to prevent, and
 * this test pins the property that gives it its teeth.
 */
it('puts the tenant schema first and public second', function () {
    $config = (new PostgreSQLSchemaManager)->makeConnectionConfig([], 'tenant_acme');

    // Order is the whole point. `public` first would shadow tenant tables with central ones; tenant
    // first is what lets a tenant schema borrow `public`'s extensions (vector, fuzzystrmatch) while
    // still owning its own tables.
    expect($config['search_path'])->toBe('tenant_acme,public');
});

it('leaves the rest of the base connection config untouched', function () {
    $base = [
        'driver' => 'pgsql',
        'host' => 'db.example',
        'port' => 5432,
        'database' => 'central',
        'username' => 'someone',
        'search_path' => 'public',
    ];

    $config = (new PostgreSQLSchemaManager)->makeConnectionConfig($base, 'tenant_globex');

    expect($config)->toBe([...$base, 'search_path' => 'tenant_globex,public']);
});

it('overwrites an inherited search_path rather than appending to it', function () {
    // The base config a host hands in already carries `public`. Appending would produce
    // `public,tenant_x,public` — central-first, which silently reverses the shadowing above and would
    // make every tenant read central's tables. Pinned because the bug would look like a no-op.
    $config = (new PostgreSQLSchemaManager)->makeConnectionConfig(
        ['search_path' => 'public'],
        'tenant_initech',
    );

    expect($config['search_path'])->toBe('tenant_initech,public')
        ->and(substr_count($config['search_path'], 'public'))->toBe(1);
});
