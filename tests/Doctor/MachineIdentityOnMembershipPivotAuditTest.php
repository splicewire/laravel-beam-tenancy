<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Tenancy\Doctor\MachineIdentityOnMembershipPivotAudit;
use Splicewire\Beam\Tenancy\Models\TenantMachineIdentity;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;

/**
 * ⚠️ The claim under test is ADVISORY-NESS, not detection.
 *
 * The estate's standing rule: a check whose answer depends on the HOST must not throw. "Is there a
 * machine-shaped row in this host's `tenant_users`" is exactly that — the answer is somebody's live
 * data, it differs per host and per day, and a host carrying those rows is a host awaiting a data
 * migration rather than a broken one.
 *
 * This is not a hypothetical rule. A new event catalog once threw at boot on a resource prefix that
 * was registered at the flagship and absent at `~/Herd/tower`, and tower could not boot at all until
 * it was downgraded to a doctor finding. So these tests pin the downgrade rather than assuming it.
 */
beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);
});

function plantMachineShapedSeat(string $role = 'service'): array
{
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $user = User::create(['name' => 'Sync', 'email' => 'sync@splicewire.com', 'password' => 'x']);

    DB::table('tenant_users')->insert([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->id,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenant, $user];
}

it('reports a role outside the human vocabulary WITHOUT throwing', function () {
    plantMachineShapedSeat('service');

    $findings = (new MachineIdentityOnMembershipPivotAudit)->run();

    expect($findings)->toHaveCount(1);
    expect($findings[0]->status)->toBe(DoctorStatus::Warn);
    expect($findings[0]->detail)->toContain('service');
});

it('reports a seat whose user also holds a machine identity', function () {
    [$tenant, $user] = plantMachineShapedSeat('member');

    TenantMachineIdentity::create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->id,
        'kind' => 'sync',
        'granted_at' => now(),
    ]);

    $findings = (new MachineIdentityOnMembershipPivotAudit)->run();

    expect($findings[0]->status)->toBe(DoctorStatus::Warn);
    expect($findings[0]->detail)->toContain('machine identity');
});

/**
 * ⚠️ NEVER Fail — not merely "does not throw".
 *
 * `Warn` and `Fail` both avoid an exception, but only `Warn` keeps a doctor run's floor clear. A
 * `Fail` here would break `surgeon:audit` on every host still carrying the rows this change exists
 * to migrate, which is all of them until a later pass moves the data.
 */
it('never emits a Fail, whatever it finds', function () {
    plantMachineShapedSeat('service');

    foreach ((new MachineIdentityOnMembershipPivotAudit)->run() as $finding) {
        expect($finding->status)->not->toBe(DoctorStatus::Fail);
    }
});

it('passes cleanly when every seat is a human one', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $user = User::create(['name' => 'A Person', 'email' => 'person@example.com', 'password' => 'x']);

    DB::table('tenant_users')->insert([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->id,
        'role' => 'admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $findings = (new MachineIdentityOnMembershipPivotAudit)->run();

    expect($findings[0]->status)->toBe(DoctorStatus::Pass);
});

/**
 * A host that has not published the new table must still get a runnable audit — and one that SAYS it
 * measured nothing, per {@see \Rushing\Doctor\DoctorAudit}'s warning that a Pass over an empty
 * population means "nothing here", not "measured clean".
 */
it('degrades to a self-describing pass when the tables are absent', function () {
    Schema::drop('tenant_machine_identities');
    Schema::drop('tenant_users');

    $findings = (new MachineIdentityOnMembershipPivotAudit)->run();

    expect($findings[0]->status)->toBe(DoctorStatus::Pass);
    expect($findings[0]->detail)->toContain('no `tenant_users` table');
});

/**
 * ⛔ The load-bearing one: a host in the bad state still BOOTS.
 *
 * The audit is registered into the doctor manifest at boot, so the failure mode being excluded is an
 * audit that runs — or throws — during provider boot rather than on demand. Planting the worst row
 * available and then re-registering the provider is the closest this harness gets to "boot a host
 * whose data is dirty".
 */
it('lets a host in the bad state boot', function () {
    plantMachineShapedSeat('service');

    app()->register(Splicewire\Beam\Tenancy\BeamTenancyServiceProvider::class, force: true);

    expect(app()->make(MachineIdentityOnMembershipPivotAudit::class))
        ->toBeInstanceOf(MachineIdentityOnMembershipPivotAudit::class);
});

/**
 * ⛔ realm-and-floor-reconciliation, defect 2 — the pin, proven by a host where it MATTERS.
 *
 * Every test above runs in a harness whose central connection and default connection are the same
 * object (`testing`), so they cannot tell a pinned read from an unpinned one — which is exactly how the
 * unpinned read survived. This one separates them: the seats live on a `central` connection the host
 * declares through stancl's own `tenancy.database.central_connection`, and the ambient default carries a
 * pivot that is empty and clean. An unpinned reader sees nothing and reports a Pass. The audit must Warn.
 *
 * This is the **database-per-tenant** host in miniature, which is the topology the pin is for. It is
 * NOT what this estate runs: here `Splicewire\Beam\Tenancy\PostgreSQLSchemaManager` sets the tenant
 * `search_path` to `"$tenantSchema,public"` on purpose, so a central-only table falls through to
 * `public` and the unpinned read worked. Give a host a separate database per tenant and there is no
 * shared `public` to fall through to: `tenant_users` is not on a different connection, it is absent.
 */
it('reads the declared central connection, not the ambient default', function () {
    $path = sys_get_temp_dir().'/beam-tenancy-central-'.getmypid().'-'.bin2hex(random_bytes(6)).'.sqlite';
    touch($path);

    config(['database.connections.central' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
    config(['tenancy.database.central_connection' => 'central']);

    Schema::connection('central')->create('tenant_users', function ($table) {
        $table->string('tenant_id');
        $table->uuid('user_id');
        $table->string('role')->default('member');
        $table->timestamps();
        $table->primary(['tenant_id', 'user_id']);
    });

    DB::connection('central')->table('tenant_users')->insert([
        'tenant_id' => 'acme',
        'user_id' => (string) Str::uuid(),
        'role' => 'service',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The default connection's pivot is real, reachable and spotless — so a reader that resolves the
    // ambient default reports a clean Pass and the assertions below fail. That is the point of the test.
    expect(DB::table('tenant_users')->count())->toBe(0);

    $findings = (new MachineIdentityOnMembershipPivotAudit)->run();

    expect($findings[0]->status)->toBe(DoctorStatus::Warn);
    expect($findings[0]->detail)->toContain('service');

    @unlink($path);
});

/**
 * The other half of the seam, and the half that decides whether this change is safe to ship: a host that
 * names a central connection it does not define must NOT make the audit go blind.
 *
 * A literal `DB::connection('central')` would throw here, be swallowed by the class's catch-all, and
 * report a self-describing Pass — an audit reporting green because it never ran, which is this estate's
 * recurring defect class. {@see \Splicewire\Beam\Tenancy\Support\TenancyConnections::central()} answers
 * `null` for a dangling name, so the read lands on the connection the caller would have used anyway.
 */
it('still detects when the host names a central connection it never defined', function () {
    plantMachineShapedSeat('service');

    // Set AFTER planting, deliberately: stancl resolves its own Tenant model off this same key, so a
    // dangling value breaks tenant creation before the audit is ever reached. The claim under test is
    // narrower than "a host can run this way" — it is that THIS audit degrades to the default connection
    // instead of to a silent pass.
    config(['tenancy.database.central_connection' => 'no_such_connection']);

    $findings = (new MachineIdentityOnMembershipPivotAudit)->run();

    expect($findings[0]->status)->toBe(DoctorStatus::Warn);
    expect($findings[0]->detail)->toContain('service');
});
