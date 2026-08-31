<?php

use Splicewire\Beam\Tenancy\Models\TenantMachineIdentity;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;

/**
 * `Tenant::machineIdentities()` and `machineIdentityFor()` — the relation and the lookup that replace
 * `wherePivot('role', 'broker')->first()`, the pattern that only ever worked because the machine axis
 * was borrowed into the human role column.
 */
beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);
});

function grantMachineIdentity(Tenant $tenant, string $kind, array $attributes = []): TenantMachineIdentity
{
    $user = User::create([
        'name' => ucfirst($kind),
        'email' => $kind.'-'.uniqid().'@splicewire.com',
        'password' => 'x',
    ]);

    return TenantMachineIdentity::create(array_merge([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->id,
        'kind' => $kind,
        'granted_at' => now(),
    ], $attributes));
}

it('lists the machine identities granted in this tenant', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $other = Tenant::create(['id' => 'other', 'name' => 'Other']);

    grantMachineIdentity($tenant, 'sync');
    grantMachineIdentity($tenant, 'system');
    grantMachineIdentity($other, 'sync');

    $kinds = $tenant->machineIdentities()->pluck('kind')->all();

    sort($kinds);

    // The other tenant's grant is not in this list — a hasMany, scoped by tenant_id.
    expect($kinds)->toBe(['sync', 'system']);
    expect($tenant->machineIdentities()->count())->toBe(2);
});

it('finds a live grant by kind', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $grant = grantMachineIdentity($tenant, 'sync');

    expect($tenant->machineIdentityFor('sync')->id)->toBe($grant->id);
});

it('returns null for a kind this tenant has not granted', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    grantMachineIdentity($tenant, 'sync');

    expect($tenant->machineIdentityFor('broker'))->toBeNull();
});

/**
 * A revoked grant is not a grant. Every caller of this lookup is asking "may this machine act",
 * never "did it ever exist" — so the revoked row must not answer.
 */
it('ignores a revoked grant', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    grantMachineIdentity($tenant, 'sync', ['revoked_at' => now()]);

    expect($tenant->machineIdentityFor('sync'))->toBeNull();
});

it('scopes the lookup to the tenant', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $other = Tenant::create(['id' => 'other', 'name' => 'Other']);

    grantMachineIdentity($tenant, 'sync');

    expect($other->machineIdentityFor('sync'))->toBeNull();
});

/**
 * ⚠️ An UNREGISTERED kind resolves to "no grant", not an error.
 *
 * Validating `$kind` against the registry here would make a lookup's success depend on provider boot
 * order, and would break exactly the cross-host case the registry is open for.
 */
it('answers a kind no registry knows about without throwing', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    expect($tenant->machineIdentityFor('a-kind-from-some-other-host'))->toBeNull();
});

it('resolves the tenant and user behind a grant', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $grant = grantMachineIdentity($tenant, 'sync');

    expect($grant->tenant->getKey())->toBe($tenant->getKey());
    expect($grant->user)->toBeInstanceOf(User::class);
});

it('reports whether a grant is active', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    expect(grantMachineIdentity($tenant, 'sync')->isActive())->toBeTrue();
    expect(grantMachineIdentity($tenant, 'system', ['revoked_at' => now()])->isActive())->toBeFalse();
});

/**
 * ⚠️ The billing-exclusion property, asserted structurally.
 *
 * beam-commerce's `PerSeatFees` counts seats by filtering on `accepted_at`. This table has no such
 * column, so a machine cannot be counted by that meter — exclusion by construction rather than by a
 * filter someone has to remember. If a later change adds `accepted_at` "for symmetry" with
 * `tenant_users`, this test is what should stop it.
 */
it('carries no membership-lifecycle columns for a per-seat meter to find', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $grant = grantMachineIdentity($tenant, 'sync');

    $columns = array_keys($grant->fresh()->getAttributes());

    expect($columns)->not->toContain('accepted_at');
    expect($columns)->not->toContain('invited_at');
    expect($columns)->not->toContain('removed_at');
    expect($columns)->not->toContain('role');

    // And the surrogate key `tenant_users` never had.
    expect($columns)->toContain('id');
    expect($columns)->toContain('granted_at');
});
