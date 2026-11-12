<?php

use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Tenancy\Seats\SeatRolePermissions;
use Splicewire\Beam\Tenancy\Seats\WorkspaceSeats;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/*
 * Launch ticket 41 (owner ruling 2026-11-12, option A): the tenant `Admin` role, which a workspace's owner and admin seats
 * hold (beam.tenancy.seat_roles), holds `billing.view` by PACKAGE default, so those seats open billing on every host; a
 * member's `User` role does not, and no other role gains it. TENANT realm only. The readers ask a plain
 * `can('billing.view')` (laravel-beam-commerce BillController, tower SubTabVisibility), which spatie's Gate::before answers
 * from the permission row, so that is what these assertions ask. One sqlite database, as in SeatRolePermissionsTest.
 *
 * DEFAULTS ONLY (orch-lead 18:40Z): this change adds no back-fill. A seat granted from now on carries billing.view; tenants
 * whose Admin role already holds seats get it from migration-flatten's seed step (SeatRolePermissionsSeeder, which converges
 * the whole seat_role_permissions map), in its own owner-window landing.
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

    $this->tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $this->seats = app(WorkspaceSeats::class);
});

function t41User(string $email): User
{
    return User::create(['name' => 'Seat', 'email' => $email, 'password' => 'x']);
}

/** Inside the tenant: may this seat's tenant mirror `can('billing.view')`? (spatie's Gate::before, from the permission row.) */
function t41Can(Tenant $tenant, User $user): bool
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->initialize($tenant);
    try {
        return Gate::forUser(TenantUser::query()->findOrFail($user->getKey()))->allows('billing.view');
    } finally {
        tenancy()->end();
    }
}

/** @return list<string> every role that holds billing.view */
function t41Holders(): array
{
    return Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'billing.view'))->pluck('name')->sort()->values()->all();
}

it('is in the package default for the tenant Admin role, beside analytics.view', function () {
    expect(SeatRolePermissions::DEFAULTS)->toBe(['Admin' => ['analytics.view', 'billing.view']])
        ->and(SeatRolePermissions::map()['Admin'])->toContain('billing.view');
});

it('lets a tenant\'s owner open billing, once tenancy seats the owner', function () {
    $owner = t41User('owner@example.test');

    $this->tenant->assignOwner($owner);

    expect(t41Can($this->tenant, $owner))->toBeTrue()
        ->and(t41Holders())->toBe(['Admin']);
});

it('lets an admin seat open billing, refuses a member, and grants no other role', function () {
    $admin = t41User('admin@example.test');
    $member = t41User('member@example.test');

    $this->seats->seat($this->tenant, $member, 'member');
    $this->seats->seat($this->tenant, $admin, 'admin');

    expect(t41Can($this->tenant, $admin))->toBeTrue()
        ->and(t41Can($this->tenant, $member))->toBeFalse()
        ->and(t41Holders())->toBe(['Admin'], 'Admin only: User and Silo Editor gain nothing');
});

it('refuses even the owner when the host opts out of the default', function () {
    config(['beam.tenancy.seat_role_permissions' => ['Admin' => ['analytics.view']]]);
    $owner = t41User('owner@example.test');

    $this->tenant->assignOwner($owner);

    expect(t41Can($this->tenant, $owner))->toBeFalse()
        ->and(Permission::query()->where('name', 'billing.view')->exists())->toBeFalse();
});
