<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\RecordingTenantDatabaseManager;
use Splicewire\Beam\Tenancy\Tests\Fixtures\RecordingTenantsMigrateCommand;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'auth.providers.users.model' => User::class,
        'beam.accounts.user_model' => null,
        'beam.tenancy.demo.tenant.slug' => 'beam_demo',
        'beam.tenancy.demo.tenant.name' => 'Beam Demo',
    ]);

    RecordingTenantDatabaseManager::reset();
    RecordingTenantsMigrateCommand::reset();
});

/**
 * Turn this harness into a host that HAS tenant databases: register a recording manager for the
 * sqlite driver and a recording `tenants:migrate`. Both are opt-in per test, because the harness's
 * default posture — no manager, no command — is itself a case worth pinning.
 */
function hostWithTenantDatabases(): void
{
    config(['tenancy.database.managers.sqlite' => RecordingTenantDatabaseManager::class]);

    Artisan::registerCommand(new RecordingTenantsMigrateCommand);
}

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

/**
 * ⚠️ THE REGRESSION.
 *
 * The first cut of this seeder created the tenant row and stopped. At the flagship the host's
 * `TenantCreated` pipeline is `shouldBeQueued(true)` against a redis queue with no worker, so
 * nothing provisioned it: the row landed carrying `owner_email` and nothing else — no
 * `tenancy_db_name`, no schema — and `$tenant->run(...)` threw `Database tenant_beam-demo does
 * not exist.` Every other tenant in the estate (`system`, `demo`, `entreport`) carries the key
 * and has the schema.
 *
 * That is a landmine, not a cosmetic gap: the estate sweeps with a bare `Tenant::all()` loop, and
 * a tenant that throws on connect ABORTS the loop, leaving a partial list that reads like a
 * complete one.
 */
it('REGRESSION: provisions the tenant storage, so the row is one a sweep can connect to', function () {
    hostWithTenantDatabases();

    $tenant = seedDemoTenant();

    // The key stancl derives the connection from — absent on the broken row.
    expect($tenant->getInternal('db_name'))->toBe('tenant_beam_demo')
        ->and(DB::table('tenants')->where('id', 'beam_demo')->value('data'))
        ->toContain('tenancy_db_name');

    // And the storage was actually asked for, once.
    expect(RecordingTenantDatabaseManager::$created)->toBe(['tenant_beam_demo']);
});

it('migrates the new schema, SCOPED to its own tenant key and never fanned out', function () {
    hostWithTenantDatabases();

    seedDemoTenant();

    expect(RecordingTenantsMigrateCommand::$calls)->toBe([['beam_demo']]);
});

it('provisions idempotently — a second run creates no second database', function () {
    hostWithTenantDatabases();

    seedDemoTenant();
    seedDemoTenant();

    // stancl's own `ensureTenantCanBeCreated()` THROWS on an existing database rather than
    // no-opping, so the seeder must check before dispatching, not lean on the job.
    expect(RecordingTenantDatabaseManager::$created)->toBe(['tenant_beam_demo'])
        ->and(RecordingTenantsMigrateCommand::$calls)->toHaveCount(2);
});

it('leaves storage alone when the host already provisioned it', function () {
    hostWithTenantDatabases();

    // A host whose TenantCreated pipeline ran normally: the schema is already there.
    RecordingTenantDatabaseManager::$existing = ['tenant_beam_demo'];

    seedDemoTenant();

    expect(RecordingTenantDatabaseManager::$created)->toBe([]);
});

it('still seats the roster on a host with no tenant-database manager, and warns instead of throwing', function () {
    // The harness's default posture, and a real one: `tenancy.database.managers` is empty. Whether
    // this host provisions tenant databases is a fact about the HOST, so it is advisory.
    $tenant = seedDemoTenant();

    expect($tenant->getInternal('db_name'))->toBeNull()
        ->and($tenant->members())->toHaveCount(3);
});

it('does not provision when the host says something else owns the storage', function () {
    hostWithTenantDatabases();
    config(['beam.tenancy.demo.tenant.provision' => false]);

    $tenant = seedDemoTenant();

    expect(RecordingTenantDatabaseManager::$created)->toBe([])
        ->and(RecordingTenantsMigrateCommand::$calls)->toBe([])
        ->and($tenant->members())->toHaveCount(3);
});

/**
 * The hyphen. `tenant_beam-demo` is not a legal bare Postgres identifier, and the slug is spliced
 * into a schema name, a `search_path`, a Redis `prefix_base` and a search-index name. Whether the
 * slug is a bare identifier is the one thing the DECLARATION's author can get right without
 * knowing the host — so this one throws where the rest of the class warns.
 */
it('refuses a slug that is not a bare identifier — the shipped default was hyphenated', function () {
    config(['beam.tenancy.demo.tenant.slug' => 'beam-demo']);

    expect(fn () => app(DemoTenantSeeder::class)->run())
        ->toThrow(InvalidArgumentException::class, 'beam-demo');

    expect(DB::table('tenants')->count())->toBe(0);
});

it('ships an underscore default, matching every tenant id already in the estate', function () {
    // Re-read the package config rather than the value beforeEach set, so this pins the SHIPPED
    // default and not the test's own fixture.
    $shipped = require __DIR__.'/../config/beam/tenancy.php';

    expect($shipped['demo']['tenant']['slug'])->toBe('beam_demo')
        ->and($shipped['demo']['tenant']['provision'])->toBeTrue();
});
