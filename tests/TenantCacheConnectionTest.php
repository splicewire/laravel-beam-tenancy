<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;

/*
 * Launch row qitem-20261016172734-edfd969d (blocks G6): the market deploy's `splicewire:beam:install` aborted because
 * DemoTenantSeeder::seatInTenant(), inside `$tenant->run()`, created a role, and spatie's permission registrar cleared
 * its cache through the `database` cache store, which (with `connection => null`) follows the DEFAULT connection.
 * Tenancy had switched the default to `tenant`, whose schema has no `cache` table:
 * SQLSTATE[42P01] relation "cache" does not exist (Connection: tenant).
 *
 * The cache table is central-only on every tenancy host in the estate, so a database cache store with no connection of
 * its own is pinned to the central connection at register time, while the default still IS central.
 */

beforeEach(function () {
    Schema::connection('testing')->create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    Schema::connection('testing')->create('cache_locks', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });

    // A tenant connection whose schema has no cache table, exactly like a tenant schema on a real host.
    config(['database.connections.tenant_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    config(['cache.default' => 'database']);
    // This harness loads no spatie config (it deliberately has no permission tables); give the registrar the
    // stock models and cache settings so it can be built, exactly as a host's config/permission.php does.
    config([
        'permission.models.permission' => Spatie\Permission\Models\Permission::class,
        'permission.models.role' => Spatie\Permission\Models\Role::class,
        'permission.cache.store' => 'default',
        'permission.cache.key' => 'spatie.permission.cache',
        'permission.cache.expiration_time' => DateInterval::createFromDateString('24 hours'),
    ]);
    Cache::forgetDriver('database');
    app()->forgetInstance(PermissionRegistrar::class);
});

afterEach(function () {
    DB::setDefaultConnection('testing');
});

it('pins the database cache store to the central connection when the host left it unset', function () {
    expect(config('cache.stores.database.connection'))->toBe('testing')
        ->and(config('cache.stores.database.lock_connection'))->toBe('testing');
});

it('keeps database cache reads and writes on central while tenancy has switched the default connection', function () {
    DB::setDefaultConnection('tenant_probe');

    Cache::store('database')->put('beam-probe', 'v', 60);
    expect(Cache::store('database')->get('beam-probe'))->toBe('v');
    Cache::store('database')->forget('beam-probe');

    expect(Schema::connection('tenant_probe')->hasTable('cache'))->toBeFalse()
        ->and(DB::connection('testing')->table('cache')->count())->toBe(0);
});

it('lets spatie clear its permission cache inside a tenant context (the DemoTenantSeeder path)', function () {
    DB::setDefaultConnection('tenant_probe');

    // What findOrCreate('Admin') does on a new role, and what PermissionsTenancyBootstrapper does on every switch.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(true)->toBeTrue();
});

it('never overrides a host that chose its own cache connection', function () {
    $stores = BeamTenancyServiceProvider::pinCentralDatabaseCacheStores([
        'database' => ['driver' => 'database', 'connection' => 'cache_db', 'lock_connection' => null],
        'redis' => ['driver' => 'redis', 'connection' => 'cache'],
    ], 'central');

    // An unset lock connection follows the host's chosen store connection, as Laravel's own resolution does;
    // splitting locks onto central would move them off the host's choice.
    expect($stores['database']['connection'])->toBe('cache_db')
        ->and($stores['database']['lock_connection'])->toBe('cache_db')
        ->and($stores['redis']['connection'])->toBe('cache');
});
