<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * What replaced `TenantInvitationResourceTest` when the model it tested was deleted.
 *
 * That file asserted two declarations this package no longer makes — a `tenant-invitations` particle
 * resource and a `manageTenantInvitations` ability — and both existed for the same single reason:
 * `beam_invitations.team_id` was a bigint FK into `beam_teams`, so a string-keyed `Tenant` could
 * implement `TeamContract` by interface and still not be nameable by an invitation. The column is a
 * `TeamContract` key now, so `splicewire/laravel-beam-accounts`' own `Invitation` serves this case and
 * the fork is retired: the resource key is uncontested (`invitations`, one model behind it) and the
 * ability lives beside the one it delegates to (`manageInvitation` on `MembershipPolicy`).
 *
 * ⚠️ A retirement test that only asserts absence is the estate's signature vacuous check — it would
 * pass just as well if the provider stopped booting. So each case below pairs the absence with the
 * POSITIVE fact that made the absence safe.
 */
beforeEach(function (): void {
    // `Invitation` reads `beam.accounts.invitations.connection`; a tenanted host pins `central`, which
    // is exactly the seam under test here. The harness gives it the same second in-memory database
    // `TenantResourceTest` builds.
    config(['database.connections.central' => config('database.connections.testing')]);
    config(['beam.accounts.invitations.connection' => 'central']);

    Schema::connection('central')->create('beam_invitations', function (Blueprint $table): void {
        $table->id();
        $table->string('team_id')->index();
        $table->string('email');
        $table->string('role')->default('member');
        $table->string('token')->unique();
        $table->uuid('invited_by')->nullable();
        $table->timestamp('accepted_at')->nullable();
        $table->timestamps();
        $table->unique(['team_id', 'email']);
    });
});

afterEach(function (): void {
    config(['beam.accounts.invitations.connection' => null]);
});

it('registers no invitation resource of its own, while still registering the tenants one', function () {
    app()->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    $provider = new BeamTenancyServiceProvider(app());
    $provider->register();
    $provider->boot();

    $registry = app(ParticleResourceRegistry::class);

    // The positive half, without which the absence below proves nothing: the provider's
    // `bootFrameResources()` really ran and really registered into THIS registry.
    expect($registry->has('tenants'))->toBeTrue();

    // The key existed only to disambiguate two models behind one string. There is one model.
    expect($registry->has('tenant-invitations'))->toBeFalse();
});

it('reads pending invitations off the packaged model, keyed by the tenant string id', function () {
    $tenant = Tenant::create(['id' => 'beam_demo', 'name' => 'Demo', 'slug' => 'demo']);
    $other = Tenant::create(['id' => 'other', 'name' => 'Other', 'slug' => 'other']);

    $pending = Invitation::create(['team_id' => $tenant->getKey(), 'email' => 'p@example.test', 'role' => 'member', 'token' => 'a']);
    Invitation::create(['team_id' => $tenant->getKey(), 'email' => 'acc@example.test', 'role' => 'member', 'token' => 'b', 'accepted_at' => now()]);
    Invitation::create(['team_id' => $other->getKey(), 'email' => 'f@example.test', 'role' => 'member', 'token' => 'c']);

    $rows = Tenant::find('beam_demo')->invitations()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->getKey())->toBe($pending->getKey());
});

it('cascades on tenant delete, replacing the foreign key that went with the fork', function () {
    $tenant = Tenant::create(['id' => 'beam_demo', 'name' => 'Demo', 'slug' => 'demo']);
    $survivor = Tenant::create(['id' => 'keep', 'name' => 'Keep', 'slug' => 'keep']);

    Invitation::create(['team_id' => 'beam_demo', 'email' => 'p@example.test', 'role' => 'member', 'token' => 'a']);
    Invitation::create(['team_id' => 'beam_demo', 'email' => 'acc@example.test', 'role' => 'member', 'token' => 'b', 'accepted_at' => now()]);
    Invitation::create(['team_id' => 'keep', 'email' => 'k@example.test', 'role' => 'member', 'token' => 'c']);

    expect(Invitation::count())->toBe(3);

    $tenant->delete();

    // Both rows go, accepted included — the FK deleted the historical ones too, and a live bearer
    // token naming a workspace that no longer exists is the thing this replaces.
    expect(Invitation::count())->toBe(1)
        ->and(Invitation::first()->team_id)->toBe('keep')
        ->and($survivor->exists)->toBeTrue();
});

it('pins the invitations connection through config rather than a hardcoded central', function () {
    // The seam that let one packaged model serve this tenanted package at all. Asserted here rather
    // than only in beam-accounts because THIS is the package whose hosts must set it.
    expect((new Invitation)->getConnectionName())->toBe('central');

    config(['beam.accounts.invitations.connection' => null]);

    expect((new Invitation)->getConnectionName())->toBeNull();
});
