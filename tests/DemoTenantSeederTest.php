<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'auth.providers.users.model' => User::class,
        'beam.accounts.user_model' => null,
        'beam.tenancy.demo.tenant.slug' => 'beam-demo',
        'beam.tenancy.demo.tenant.name' => 'Beam Demo',
    ]);
});

function seedDemoTenant(): Tenant
{
    app(DemoTenantSeeder::class)->run();

    return Tenant::find(config('beam.tenancy.demo.tenant.slug'));
}

it('creates the dedicated demo tenant and seats the shared demo roster on it', function () {
    $tenant = seedDemoTenant();

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Beam Demo');

    // One seat per SHARED subject in beam-accounts' role-derived roster — Owner/Admin/Member.
    $shared = array_filter(BeamDemo::subjects(), fn (array $s): bool => $s['shared']);

    expect($tenant->members())->toHaveCount(count($shared));

    foreach ($shared as $key => $subject) {
        $user = User::where('email', BeamDemo::email($key))->first();

        expect($user)->not->toBeNull("demo subject [{$key}] was not provisioned")
            ->and($tenant->hasMember($user))->toBeTrue()
            ->and($tenant->memberRole($user))->toBe($subject['role']);
    }
});

it('seats the roster as accepted members, not pending invitations', function () {
    $tenant = seedDemoTenant();

    $member = User::where('email', BeamDemo::email(Role::Member->value))->firstOrFail();
    $pivot = $tenant->users()->wherePivot('user_id', $member->getKey())->firstOrFail()->pivot;

    expect($pivot->accepted_at)->not->toBeNull()
        ->and($pivot->removed_at)->toBeNull();
});

it('excludes the solo subject — it models a team-of-one shape, not a tenant seat', function () {
    $tenant = seedDemoTenant();

    expect(User::where('email', BeamDemo::email('solo'))->exists())->toBeFalse()
        ->and($tenant->users()->count())->toBe(3);
});

it('records the Owner-role subject as the tenant owner_email', function () {
    $tenant = seedDemoTenant();

    expect($tenant->owner_email)->toBe(BeamDemo::email(Role::Owner->value));
});

it('is idempotent — a second run duplicates no seat, no user and no tenant', function () {
    seedDemoTenant();

    $before = [
        'tenants' => DB::table('tenants')->count(),
        'users' => DB::table('users')->count(),
        'seats' => DB::table('tenant_users')->get()->map(fn ($r) => (array) $r)->toArray(),
    ];

    seedDemoTenant();

    $after = [
        'tenants' => DB::table('tenants')->count(),
        'users' => DB::table('users')->count(),
        'seats' => DB::table('tenant_users')->get()->map(fn ($r) => (array) $r)->toArray(),
    ];

    // Row-for-row identical, INCLUDING `accepted_at` — the second run must not re-stamp it.
    expect($after)->toBe($before)
        ->and($before['tenants'])->toBe(1)
        ->and($before['users'])->toBe(3)
        ->and($before['seats'])->toHaveCount(3);
});

it('skips itself when the demo affordances are off (production posture)', function () {
    config(['beam.accounts.demo.enabled' => false]);

    app(DemoTenantSeeder::class)->run();

    expect(DB::table('tenants')->count())->toBe(0)
        ->and(DB::table('tenant_users')->count())->toBe(0);
});

it('registers itself into the beam seed manifest after beam-accounts, behind a config gate', function () {
    $step = collect(app(BeamSeedManifest::class)->steps())
        ->firstWhere('package', 'splicewire/laravel-beam-tenancy');

    expect($step)->not->toBeNull()
        ->and($step->seeder)->toBe(DemoTenantSeeder::class)
        ->and($step->configGate)->toBe('beam.tenancy.demo.seed_tenant')
        // beam-accounts' DemoTeamSeeder registers at 10; the demo users must exist first.
        ->and($step->order)->toBeGreaterThan(10)
        // Null resolves to `! production` at boot, and the resolved boolean is written back so
        // the manifest's raw `config($gate)` read sees the effective value.
        ->and(config('beam.tenancy.demo.seed_tenant'))->toBeTrue();
});

/**
 * ⚠️ THE DELIVERABLE.
 *
 * The dataset must DISCRIMINATE a gate — that is the whole reason this seeder exists. Before it,
 * the estate had a role-differentiated demo roster with zero central spatie roles (good) that was
 * seated on no tenant at all (useless), and every real `tenant_users` seat belonged to a root
 * account (useless in the other direction). No test could obtain "an authenticated, role-less,
 * genuine tenant member" and therefore no authorization regression test could be written.
 *
 * `manageInvitations` is beam-accounts' own {@see MembershipPolicy} ability. It is registered here
 * with the single `Gate::define` line beam-accounts' provider runs (that provider is not booted in
 * this package's harness), and it reads the actor's role through `TeamContract::memberRole()` —
 * i.e. off the seat, and off nothing else.
 *
 * The gate is CLOSED for this check: no `Gate::before`, no ambient allow. The estate has been
 * burned by authorization traces taken with a `Gate::before(fn () => true)` open, which measure
 * nothing at all.
 */
it('DELIVERABLE: the seeded dataset discriminates an authorization gate', function () {
    Gate::define('manageInvitations', [MembershipPolicy::class, 'manageInvitations']);
    Gate::define('manageMembers', [MembershipPolicy::class, 'manageMembers']);

    $tenant = seedDemoTenant();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();
    $admin = User::where('email', BeamDemo::email(Role::Admin->value))->firstOrFail();
    $member = User::where('email', BeamDemo::email(Role::Member->value))->firstOrFail();
    $outsider = User::create(['name' => 'Outsider', 'email' => 'outsider@example.test']);

    // Precondition: NOBODY here holds a central grant. There are no spatie tables in this harness,
    // so the seat is provably the only authorization signal in play.
    expect(Schema::hasTable('roles'))->toBeFalse()
        ->and(Schema::hasTable('model_has_roles'))->toBeFalse()
        ->and(Schema::hasTable('permissions'))->toBeFalse();

    // Precondition: the member is a GENUINE member of the tenant.
    expect($tenant->hasMember($member))->toBeTrue()
        ->and($tenant->memberRole($member))->toBe(Role::Member);

    // The discrimination. Same tenant, same gate, three different answers, all from seeded data.
    expect($admin->can('manageInvitations', $tenant))->toBeTrue()
        ->and($member->can('manageInvitations', $tenant))->toBeFalse()
        ->and($outsider->can('manageInvitations', $tenant))->toBeFalse();

    // And the stricter tier discriminates Admin from Owner, so the roster spans both rungs.
    expect($owner->can('manageMembers', $tenant))->toBeTrue()
        ->and($admin->can('manageMembers', $tenant))->toBeFalse()
        ->and($member->can('manageMembers', $tenant))->toBeFalse();
});
