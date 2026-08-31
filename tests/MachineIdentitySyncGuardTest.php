<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Tenancy\Listeners\MachineIdentityAwareUpdateSyncedResource;
use Splicewire\Beam\Tenancy\Models\TenantMachineIdentity;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;
use Stancl\Tenancy\Tenancy;

/**
 * ⛔ The guard that makes the machine-identity split safe.
 *
 * Stancl re-attaches a missing tenant↔user pivot mapping with NO attributes
 * (`vendor/stancl/tenancy/src/Listeners/UpdateSyncedResource.php:73-81`), so `role` takes the column
 * default `'member'`. `TenantUser` is `Syncable`, and every machine-provisioning path in the estate
 * ends in a `TenantUser::updateOrCreate` inside `$tenant->run()`. So without a guard, the first sync
 * after a machine row leaves `tenant_users` puts it straight back — as an ordinary-looking `member`
 * seat that no role read, no seat list and no billing meter can tell from a human's.
 *
 * ⚠️ THIS FILE WAS WATCHED FAIL BEFORE THE LISTENER WAS BOUND. With the binding removed from
 * `BeamTenancyServiceProvider::register()`, `it does not re-attach a machine identity as a member
 * seat` fails with a `role = 'member'` row present — the exact defect, reproduced. That is the only
 * thing that makes the green meaningful, and it is why the negative control below exists too.
 */
beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);

    // ⚠️ The two stancl bindings `$tenant->run()` cannot work without, and whose ABSENCE produced a
    // convincing false red on the first attempt at this file.
    //
    // This harness does not boot stancl's `TenancyServiceProvider` (see TestCase). Without these,
    // `tenancy()` resolves a FRESH `Tenancy` on every call — so `run()` initialises one instance and
    // `tenant()` reads another, which is null. `SyncedResourceSaved` then carries a null tenant,
    // stancl takes its central-context branch instead of the attach branch, and every test in this
    // file died on `ModelNotSyncMasterException` — a red that looks like a real failure and proves
    // nothing about the guard, because the code under test never ran.
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);

    // Mirrors a real host's `$listen` map — the flagship's `TenancyServiceProvider:117` registers
    // stancl's class-string, NOT ours. Registering the stancl name here is deliberate and is half
    // the claim: the container binding must be what swaps the implementation, because no host is
    // ever going to edit its listener map for this.
    Event::listen(SyncedResourceSaved::class, UpdateSyncedResource::class);
});

/** A tenant and a central user, with no seat and no machine identity between them yet. */
function machineIdentityFixture(): array
{
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    $user = User::create([
        'name' => 'Sync Daemon',
        'email' => 'sync@splicewire.com',
        'password' => 'x',
    ]);

    return [$tenant, $user];
}

/**
 * Triggering the sync exactly as the estate's machine-provisioning paths do — tower's
 * `SyncServiceUser:37` and the flagship's `EngineConsumerToken` are both this call.
 *
 * Bootstrappers stay OFF (the harness registers no `BootstrapTenancy` listener), so
 * `tenancy()->initialize()` sets the current tenant and fires the event without switching the
 * connection. The database switch is irrelevant to the guard; the tenant being SET is the whole
 * mechanism, since that is what `tenant()` hands `SyncedResourceSaved`.
 */
function syncTenantUser(Tenant $tenant, User $user): void
{
    $tenant->run(fn () => TenantUser::updateOrCreate(
        ['id' => $user->id],
        ['name' => $user->name, 'email' => $user->email, 'password' => $user->password],
    ));
}

function seats(Tenant $tenant, User $user): array
{
    return DB::table('tenant_users')
        ->where('tenant_id', $tenant->getKey())
        ->where('user_id', $user->id)
        ->get()
        ->all();
}

/**
 * ⚠️ THE NEGATIVE CONTROL, and it must run first in spirit: it proves the harness actually reaches
 * stancl's attach branch.
 *
 * Without this, the guard test above could pass because nothing ever fired — the estate's signature
 * defect class, an instrument that reports success by not running. This test failing is the tell
 * that the guard test has stopped measuring anything.
 */
it('still attaches an ordinary user with no machine identity — the mechanism is live', function () {
    [$tenant, $user] = machineIdentityFixture();

    syncTenantUser($tenant, $user);

    $rows = seats($tenant, $user);

    expect($rows)->toHaveCount(1);
    // And here is the defect in its natural habitat: nobody asked for a role, and the column
    // default supplied one.
    expect($rows[0]->role)->toBe('member');
});

it('does not re-attach a machine identity as a member seat', function () {
    [$tenant, $user] = machineIdentityFixture();

    TenantMachineIdentity::create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->id,
        'kind' => 'sync',
        'granted_at' => now(),
    ]);

    syncTenantUser($tenant, $user);

    // ZERO. A machine's presence is recorded in `tenant_machine_identities`; the absence of a
    // membership row is correct for it, not a gap for stancl to repair.
    expect(seats($tenant, $user))->toHaveCount(0);
});

it('re-attaches once the machine identity is revoked', function () {
    [$tenant, $user] = machineIdentityFixture();

    TenantMachineIdentity::create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->id,
        'kind' => 'sync',
        'granted_at' => now()->subDay(),
        'revoked_at' => now(),
    ]);

    syncTenantUser($tenant, $user);

    // A revoked grant is not a grant. If something is still syncing under it, ordinary membership
    // rules apply again rather than the row staying invisible forever.
    expect(seats($tenant, $user))->toHaveCount(1);
});

it('scopes the skip to the tenant the identity was granted in', function () {
    [$tenant, $user] = machineIdentityFixture();
    $other = Tenant::create(['id' => 'other', 'name' => 'Other']);

    TenantMachineIdentity::create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->id,
        'kind' => 'sync',
        'granted_at' => now(),
    ]);

    syncTenantUser($other, $user);

    // A machine grant in one tenant says nothing about another. Getting this wrong in the lenient
    // direction would silently stop provisioning real seats estate-wide.
    expect(seats($other, $user))->toHaveCount(1);
    expect(seats($tenant, $user))->toHaveCount(0);
});

it('binds the machine-identity-aware listener over the stancl class-string', function () {
    // The swap is a container binding precisely so no host has to edit its `$listen` map.
    expect(app(UpdateSyncedResource::class))
        ->toBeInstanceOf(MachineIdentityAwareUpdateSyncedResource::class);
});

it('fails open when the host has not published the machine-identity table', function () {
    [$tenant, $user] = machineIdentityFixture();

    Schema::drop('tenant_machine_identities');

    syncTenantUser($tenant, $user);

    // A host that never opted in must sync exactly as before. This class must never be the reason
    // a sync starts throwing on a host that has not run the new migration.
    expect(seats($tenant, $user))->toHaveCount(1);
});
