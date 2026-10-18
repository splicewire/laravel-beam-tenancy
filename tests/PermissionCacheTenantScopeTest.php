<?php

// L3 ticket 09 C4, a security fix (orch-lead 23:06Z): spatie/laravel-permission's cached collection maps each permission
// to role ids, and role ids are per database. One shared cache key let tenant A's grants land on tenant B's same-id
// role, and a central fill leave a tenant's own roles holding nothing. BeamTenancyServiceProvider keys the registrar's
// cache per tenant (bootPermissionCacheScoping). Each tenant's database is emulated here by rewriting the permission
// rows between contexts, so the SAME role id carries different permissions per tenant, exactly as two databases would.

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Tenancy;

beforeEach(function () {
    // spatie publishes its config rather than merging it, so a testbench app has none until it is loaded.
    config()->set('permission', require __DIR__.'/../vendor/spatie/laravel-permission/config/permission.php');
    config()->set('permission.cache.store', 'array');
    config()->set('permission.teams', false);
    app()->register(PermissionServiceProvider::class);
    (include __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();

    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    config()->set('tenancy.bootstrappers', []);
    Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
    Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);
});

/**
 * This database's permission rows: role #1 ("Admin") holds exactly these permissions. Raw writes, as rows already in a
 * tenant's database would be: spatie's model events flush its cache, which would hide the bleed under test.
 */
function permissionRows(string ...$permissions): int
{
    DB::table('role_has_permissions')->delete();
    DB::table('permissions')->delete();
    DB::table('roles')->delete();
    DB::table('roles')->insert(['id' => 1, 'name' => 'Admin', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
    foreach (array_values($permissions) as $i => $name) {
        DB::table('permissions')->insert(['id' => $i + 1, 'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_has_permissions')->insert(['permission_id' => $i + 1, 'role_id' => 1]);
    }

    return 1;
}

/** Whether the registrar's (cached) collection says this role holds this permission: what every permission check reads. */
function cachedGrant(int $roleId, string $permission): bool
{
    $found = app(PermissionRegistrar::class)->getPermissions(['name' => $permission])->first();

    return (bool) $found?->roles->contains('id', $roleId);
}

it("does not apply one tenant's cached grants to another tenant's same-id role", function () {
    $acme = Tenant::create(['id' => 'acme', 'slug' => 'acme']);
    $globex = Tenant::create(['id' => 'globex', 'slug' => 'globex']);

    $admin = permissionRows('acme.grant');                       // acme's database
    tenancy()->initialize($acme);
    expect(cachedGrant($admin, 'acme.grant'))->toBeTrue();
    tenancy()->end();

    expect(permissionRows('globex.only'))->toBe($admin);        // globex's database: the SAME role id
    tenancy()->initialize($globex);
    expect(cachedGrant($admin, 'acme.grant'))->toBeFalse()      // acme's grant must not reach globex's Admin
        ->and(cachedGrant($admin, 'globex.only'))->toBeTrue();
    tenancy()->end();
});

it("does not let a central fill leave a tenant's own roles holding nothing", function () {
    $acme = Tenant::create(['id' => 'acme', 'slug' => 'acme']);

    $admin = permissionRows();                                   // central: the same role id, no grants
    expect(cachedGrant($admin, 'composition.view'))->toBeFalse();    // a central request fills the cache first

    permissionRows('composition.view');                          // the tenant's database
    tenancy()->initialize($acme);
    expect(cachedGrant($admin, 'composition.view'))->toBeTrue();
    tenancy()->end();
});

it('returns the registrar to the central key when tenancy ends', function () {
    $acme = Tenant::create(['id' => 'acme', 'slug' => 'acme']);
    $central = app(PermissionRegistrar::class)->cacheKey;

    tenancy()->initialize($acme);
    expect(app(PermissionRegistrar::class)->cacheKey)->toBe("{$central}.tenant.acme");
    tenancy()->end();

    expect(app(PermissionRegistrar::class)->cacheKey)->toBe($central);
});

it('scopes a registrar that is first built inside a tenant', function () {
    $acme = Tenant::create(['id' => 'acme', 'slug' => 'acme']);
    app()->forgetInstance(PermissionRegistrar::class);

    tenancy()->initialize($acme);
    $central = (string) config('permission.cache.key');
    expect(app(PermissionRegistrar::class)->cacheKey)->toBe("{$central}.tenant.acme");
    tenancy()->end();
});

// orch-peer HIGH on aa88f09: stancl clears `initialized` only after TenancyEnded's listeners return.
it('keeps a registrar first built by a later TenancyEnded listener on the central key', function () {
    $acme = Tenant::create(['id' => 'acme', 'slug' => 'acme']);
    $central = (string) config('permission.cache.key');
    $seen = null;
    Event::listen(Events\TenancyEnded::class, function () use (&$seen): void {
        $seen = app(PermissionRegistrar::class)->cacheKey;      // first built here, while `initialized` is still true
    });

    tenancy()->initialize($acme);
    app()->forgetInstance(PermissionRegistrar::class);
    tenancy()->end();

    expect($seen)->toBe($central)
        ->and(app(PermissionRegistrar::class)->cacheKey)->toBe($central);
});
