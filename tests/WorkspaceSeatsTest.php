<?php

use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Tenancy\Seats\WorkspaceSeats;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/*
 * Launch row qitem-20261021011058-9fa6ba0e: the flagship's WorkspaceSeats, lifted. A seat gives the tenant mirror exactly
 * its tier's role (beam.tenancy.seat_roles), removes the other mapped tier roles, never touches a role outside the map
 * and ignores a tier the map does not name. This harness is one sqlite database (central users and the tenant mirror
 * share a table, and no bootstrapper switches connections), so it proves the role logic; the cross-database mirror is
 * proven at the hosts.
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

function seatUser(string $email): User
{
    return User::create(['name' => 'Seat', 'email' => $email, 'password' => 'x']);
}

function rolesOf(User $user): array
{
    return TenantUser::query()->findOrFail($user->getKey())->getRoleNames()->sort()->values()->all();
}

it('gives a member the User role and an admin or owner the Admin role', function () {
    foreach (['member' => ['User'], 'admin' => ['Admin'], 'owner' => ['Admin']] as $tier => $expected) {
        $user = seatUser("{$tier}@example.test");
        expect($this->seats->seat($this->tenant, $user, $tier))->toBeTrue()
            ->and(rolesOf($user))->toBe($expected);
    }
});

it('moves the role with the tier and is a no-op when the seat is already right', function () {
    $user = seatUser('mover@example.test');
    $this->seats->seat($this->tenant, $user, 'member');

    expect($this->seats->plan($this->tenant, $user, 'admin'))->toBe(['+Admin', '-User'])
        ->and($this->seats->seat($this->tenant, $user, 'admin'))->toBeTrue()
        ->and(rolesOf($user))->toBe(['Admin'])
        ->and($this->seats->plan($this->tenant, $user, 'admin'))->toBe([])
        ->and($this->seats->seat($this->tenant, $user, 'admin'))->toBeFalse();
});

it('never touches a role outside the tier map, and ignores an unmapped tier', function () {
    $user = seatUser('editor@example.test');
    $this->seats->seat($this->tenant, $user, 'member');
    TenantUser::query()->findOrFail($user->getKey())->assignRole('Silo Editor');

    $this->seats->seat($this->tenant, $user, 'admin');
    expect(rolesOf($user))->toBe(['Admin', 'Silo Editor']);

    expect($this->seats->plan($this->tenant, $user, 'service'))->toBe([])
        ->and($this->seats->seat($this->tenant, $user, 'service'))->toBeFalse()
        ->and(rolesOf($user))->toBe(['Admin', 'Silo Editor']);
});

it('reads the tier map from config', function () {
    config(['beam.tenancy.seat_roles' => ['member' => 'Silo Editor']]);
    $user = seatUser('mapped@example.test');

    $this->seats->seat($this->tenant, $user, 'member');

    expect(rolesOf($user))->toBe(['Silo Editor']);
});

it('reseats every live seat, lists without writing on --dry-run, and changes nothing on a second run', function () {
    $member = seatUser('backfill-member@example.test');
    $admin = seatUser('backfill-admin@example.test');
    $gone = seatUser('backfill-removed@example.test');
    $this->tenant->users()->attach($member->getKey(), ['role' => 'member', 'accepted_at' => now()]);
    $this->tenant->users()->attach($admin->getKey(), ['role' => 'admin', 'accepted_at' => now()]);
    $this->tenant->users()->attach($gone->getKey(), ['role' => 'member', 'accepted_at' => now(), 'removed_at' => now()]);

    expect(Artisan::call('splicewire:beam:tenancy:reseat', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Would change 2 seat(s)')
        ->and(TenantUser::query()->findOrFail($member->getKey())->getRoleNames()->all())->toBe([]);

    expect(Artisan::call('splicewire:beam:tenancy:reseat'))->toBe(0)
        ->and(Artisan::output())->toContain('Changed 2 seat(s)')
        ->and(rolesOf($member))->toBe(['User'])
        ->and(rolesOf($admin))->toBe(['Admin'])
        ->and(TenantUser::query()->findOrFail($gone->getKey())->getRoleNames()->all())->toBe([]);

    expect(Artisan::call('splicewire:beam:tenancy:reseat'))->toBe(0)
        ->and(Artisan::output())->toContain('Changed 0 seat(s)');
});

it('seats a member provisioned through assignMember, not only the central pivot', function () {
    $user = seatUser('provisioned@example.test');

    $this->tenant->assignMember($user, Splicewire\Beam\Accounts\Enums\Role::Member);

    expect($this->tenant->hasMember($user))->toBeTrue()
        ->and(rolesOf($user))->toBe(['User']);
});

// Found by the starter fresh-install walk (2026-10-21): the demo seeder seats members during beam:install, before the
// tenant's permission seeder has created any role, and assigning a role that does not exist threw mid-install.
it('seats a member whose tier role does not exist yet without throwing, and gives it the role once provisioning creates it', function () {
    Role::query()->where('name', 'User')->delete();
    $user = seatUser('early@example.test');

    // This harness shares one users table between central and the mirror, so the mirror already exists: the point is
    // that seating neither throws nor assigns a role the tenant does not hold yet.
    expect($this->seats->plan($this->tenant, $user, 'member'))->toBe([])
        ->and($this->seats->seat($this->tenant, $user, 'member'))->toBeFalse()
        ->and(rolesOf($user))->toBe([]);

    Role::findOrCreate('User', 'web');

    expect($this->seats->plan($this->tenant, $user, 'member'))->toBe(['+User'])
        ->and($this->seats->seat($this->tenant, $user, 'member'))->toBeTrue()
        ->and(rolesOf($user))->toBe(['User']);
});
