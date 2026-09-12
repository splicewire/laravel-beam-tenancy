<?php

use Splicewire\Beam\Tenancy\Tenant;

/**
 * pooled-storage ticket 10 — the sqlite-side wiring (gating, guards). The real bind/unbind is
 * Postgres-only; the Postgres-gated class beside this proves the mechanism.
 */
it('refuses when direct_access_roles is off — the opt-in gate', function () {
    config(['beam.tenancy.pooled.direct_access_roles' => false]);
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme', 'slug' => 'acme']);
    $tenant->markPooled('default')->save();

    $this->artisan('splicewire:beam:tenancy:pools:direct-access', ['tenant' => 'acme'])
        ->expectsOutputToContain('direct_access_roles is off')
        ->assertFailed();
});

it('refuses a tenant that is not pooled', function () {
    config(['beam.tenancy.pooled.direct_access_roles' => true]);
    Tenant::create(['id' => 'acme', 'name' => 'Acme', 'slug' => 'acme']);

    $this->artisan('splicewire:beam:tenancy:pools:direct-access', ['tenant' => 'acme'])
        ->expectsOutputToContain('is not on Pooled storage')
        ->assertFailed();
});

it('refuses an unknown tenant', function () {
    config(['beam.tenancy.pooled.direct_access_roles' => true]);

    $this->artisan('splicewire:beam:tenancy:pools:direct-access', ['tenant' => 'nope'])
        ->expectsOutputToContain("No tenant found for 'nope'")
        ->assertFailed();
});

it('refuses to revoke a tenant with no direct-access role', function () {
    config(['beam.tenancy.pooled.direct_access_roles' => true]);
    Tenant::create(['id' => 'acme', 'name' => 'Acme', 'slug' => 'acme'])->markPooled('default')->save();

    $this->artisan('splicewire:beam:tenancy:pools:direct-access', ['tenant' => 'acme', '--revoke' => true])
        ->expectsOutputToContain('has no direct-access role to revoke')
        ->assertFailed();
});

it('marks and clears the direct-access role on the tenant, storing only the name', function () {
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme', 'slug' => 'acme']);

    expect($tenant->hasDirectAccessRole())->toBeFalse();

    $tenant->markDirectAccessRole('direct_acme')->save();
    $fresh = Tenant::find('acme');

    expect($fresh->hasDirectAccessRole())->toBeTrue()
        ->and($fresh->direct_access_role)->toBe('direct_acme');

    $fresh->markDirectAccessRole(null)->save();
    expect(Tenant::find('acme')->hasDirectAccessRole())->toBeFalse();
});
