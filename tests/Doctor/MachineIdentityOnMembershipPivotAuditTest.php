<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
