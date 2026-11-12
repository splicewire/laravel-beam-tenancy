<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Tenancy\Entitlements\TenantAnalyticsEntitlement;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Tenancy\Seats\SeatRolePermissions;
use Splicewire\Beam\Tenancy\Seats\WorkspaceSeats;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/*
 * analytics-area 16 (owner ruling 2026-11-10: a PACKAGE default). A workspace's owner and admin seats hold the tenant
 * `Admin` role (beam.tenancy.seat_roles), and Admin holds `analytics.view` on every host: tenancy grants it when it seats
 * into the role, a shared migration back-fills roles that already hold seats, and inside a tenant the permission composes
 * the `analytics.view` entitlement the Analytics section's gate asks for. One sqlite database, as in WorkspaceSeatsTest.
 */
beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);

    $this->app->register(PermissionServiceProvider::class);
    (include __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
    foreach (['Admin', 'User', 'Silo Editor'] as $name) {
        Role::findOrCreate($name, 'web');
    }
    Permission::findOrCreate('site.edit', 'web');
    Role::findByName('Admin', 'web')->givePermissionTo('site.edit');

    $this->tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $this->seats = app(WorkspaceSeats::class);
});

function aa16User(string $email): User
{
    return User::create(['name' => 'Seat', 'email' => $email, 'password' => 'x']);
}

/** @return list<string> the role's permission names */
function aa16Holds(string $role): array
{
    return Role::findByName($role, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

function aa16Grants(): int
{
    return DB::table(config('permission.table_names.role_has_permissions'))->count();
}

function aa16Migration(): object
{
    return include __DIR__.'/../database/migrations/shared/grant_analytics_view_to_admin.php.stub';
}

// --- the seat-time grant ------------------------------------------------------------------------------------------

it('gives the Admin role analytics.view when an owner or an admin is seated, and nothing to a member\'s User role', function () {
    $this->seats->seat($this->tenant, aa16User('member@example.test'), 'member');
    expect(aa16Holds('User'))->toBe([])
        ->and(aa16Holds('Admin'))->toBe(['site.edit']);

    $this->seats->seat($this->tenant, aa16User('admin@example.test'), 'admin');
    expect(aa16Holds('Admin'))->toBe(['analytics.view', 'billing.view', 'site.edit'])
        ->and(aa16Holds('Silo Editor'))->toBe([]);
});

it('gives Admin analytics.view when a tenant is given its owner', function () {
    $owner = aa16User('owner@example.test');

    $this->tenant->assignOwner($owner);

    expect(aa16Holds('Admin'))->toBe(['analytics.view', 'billing.view', 'site.edit'])
        ->and(TenantUser::query()->findOrFail($owner->getKey())->getRoleNames()->all())->toBe(['Admin']);
});

it('is add-only and idempotent: seating again adds no grant and revokes nothing', function () {
    $this->seats->seat($this->tenant, aa16User('a@example.test'), 'admin');
    $grants = aa16Grants();

    $this->seats->seat($this->tenant, aa16User('b@example.test'), 'owner');
    expect(app(SeatRolePermissions::class)->ensure('Admin'))->toBe(0)
        ->and(aa16Grants())->toBe($grants)
        ->and(aa16Holds('Admin'))->toBe(['analytics.view', 'billing.view', 'site.edit']);
});

it('grants nothing when the host opts out with an empty map', function () {
    config(['beam.tenancy.seat_role_permissions' => []]);

    $this->seats->seat($this->tenant, aa16User('admin@example.test'), 'admin');

    expect(aa16Holds('Admin'))->toBe(['site.edit'])
        ->and(Permission::query()->where('name', 'analytics.view')->exists())->toBeFalse();
});

// --- the shared back-fill migration -------------------------------------------------------------------------------

it('back-fills an existing Admin role once, touches no other role, and its down() undoes exactly that grant', function () {
    $before = aa16Grants();

    aa16Migration()->up();
    expect(aa16Holds('Admin'))->toBe(['analytics.view', 'site.edit'])
        ->and(aa16Holds('User'))->toBe([])
        ->and(aa16Grants())->toBe($before + 1);

    aa16Migration()->up();
    expect(aa16Grants())->toBe($before + 1);

    aa16Migration()->down();
    expect(aa16Holds('Admin'))->toBe(['site.edit'])
        ->and(Permission::query()->where('name', 'analytics.view')->exists())->toBeFalse()
        ->and(aa16Grants())->toBe($before);
});

it('keeps the permission row in down() when another role still holds it, and skips a host that opted out', function () {
    aa16Migration()->up();
    Role::findByName('Silo Editor', 'web')->givePermissionTo('analytics.view');

    aa16Migration()->down();
    expect(aa16Holds('Admin'))->toBe(['site.edit'])
        ->and(aa16Holds('Silo Editor'))->toBe(['analytics.view']);

    Role::findByName('Silo Editor', 'web')->revokePermissionTo('analytics.view');
    Permission::query()->where('name', 'analytics.view')->delete();
    config(['beam.tenancy.seat_role_permissions' => []]);
    aa16Migration()->up();
    expect(aa16Holds('Admin'))->toBe(['site.edit']);
});

// --- the entitlement: narrow by contract ---------------------------------------------------------------------------

function aa16Resolver(array $innerKeys): EntitlementResolver
{
    app()->singleton(EntitlementResolver::class, fn () => new class($innerKeys) implements EntitlementResolver
    {
        public function __construct(private array $keys) {}

        public function entitlementsFor(mixed $principal): array
        {
            return $this->keys;
        }
    });

    return app(EntitlementResolver::class);
}

it('composes analytics.view inside a tenant for a seated admin, and for no member', function () {
    $admin = aa16User('admin@example.test');
    $member = aa16User('member@example.test');
    $this->seats->seat($this->tenant, $admin, 'admin');
    $this->seats->seat($this->tenant, $member, 'member');
    $resolver = aa16Resolver(['os.enter']);

    expect($resolver)->toBeInstanceOf(TenantAnalyticsEntitlement::class);

    tenancy()->initialize($this->tenant);
    expect($resolver->entitlementsFor(TenantUser::query()->findOrFail($admin->getKey())))->toBe(['os.enter', 'analytics.view'])
        ->and($resolver->entitlementsFor(TenantUser::query()->findOrFail($member->getKey())))->toBe(['os.enter']);
    tenancy()->end();
});

it('never widens: outside a tenant, for a principal without the permission, or for any other key, the inner answer stands', function () {
    $admin = aa16User('admin@example.test');
    $this->seats->seat($this->tenant, $admin, 'admin');
    $inner = ['ux.author', 'os.operate', 'exec.view'];
    $resolver = aa16Resolver($inner);
    $mirror = TenantUser::query()->findOrFail($admin->getKey());

    expect($resolver->entitlementsFor($mirror))->toBe($inner, 'outside a tenant')
        ->and($resolver->entitlementsFor(null))->toBe($inner)
        ->and($resolver->entitlementsFor('a-tenant-scope'))->toBe($inner);

    tenancy()->initialize($this->tenant);
    $held = $resolver->entitlementsFor($mirror);
    expect(array_values(array_diff($held, $inner)))->toBe(['analytics.view'], 'the ONLY key it adds')
        ->and(array_values(array_intersect($held, $inner)))->toBe($inner, 'every inner key unchanged');

    Role::findByName('Admin', 'web')->revokePermissionTo('analytics.view');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    expect($resolver->entitlementsFor($mirror->fresh()))->toBe($inner, 'the permission, not the role name, is what it reads');
    tenancy()->end();
});

it('leaves a host\'s own entitlement:analytics.view Gate to decide alone', function () {
    $admin = aa16User('admin@example.test');
    $this->seats->seat($this->tenant, $admin, 'admin');
    aa16Resolver([]);
    Gate::define('entitlement:analytics.view', fn ($user = null): bool => false);

    tenancy()->initialize($this->tenant);
    $mirror = TenantUser::query()->findOrFail($admin->getKey());
    expect(app(EntitlementResolver::class)->entitlementsFor($mirror))->toContain('analytics.view')
        ->and(Gate::forUser($mirror)->allows('entitlement:analytics.view'))->toBeFalse();
    tenancy()->end();
});
