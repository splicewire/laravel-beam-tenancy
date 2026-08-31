<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;
use Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder;
use Splicewire\Beam\Tenancy\Models\TenantInvitation;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * The `tenant-invitations` particle resource and the `manageTenantInvitations` ability — the two things
 * this package declares so `splicewire/tower`'s invitation OPERATIONS have a subject and a gate.
 *
 * ⚠️ GATE POSTURE: CLOSED. Nothing here installs `Gate::before`, and this harness ships no spatie
 * permission tables at all (see `TestCase::defineDatabaseMigrations()`), so a seat on `tenant_users` is
 * provably the only authorization signal in play — asserted below rather than assumed. `manageInvitations`
 * is registered with the single `Gate::define` line beam-accounts' own provider runs, because that
 * provider is not booted in this package's harness; `manageTenantInvitations` is registered by the
 * provider under test.
 */
beforeEach(function (): void {
    config(['auth.providers.users.model' => User::class]);

    // `TenantInvitation` pins the `central` connection (an invite is resolved before the invitee has any
    // tenant context), so the harness has to give it one — the same second in-memory database
    // `TenantResourceTest` builds for `CentralActivityLog`.
    config(['database.connections.central' => config('database.connections.testing')]);

    // Mirrors create_tenant_invitations_table.php.stub (FKs and the convergence guard omitted — sqlite
    // in-memory, and what is under test is the resource's scope, not referential integrity).
    Schema::connection('central')->create('tenant_invitations', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('tenant_id');
        $table->string('email');
        $table->string('role')->default('member');
        $table->string('token')->unique();
        $table->uuid('invited_by');
        $table->timestamp('accepted_at')->nullable();
        $table->timestamps();

        $table->unique(['tenant_id', 'email']);
    });
});

function inviteTo(Tenant $tenant, string $email, ?User $by = null, ?string $acceptedAt = null): TenantInvitation
{
    return TenantInvitation::create([
        'tenant_id' => $tenant->getKey(),
        'email' => $email,
        'role' => Role::Member->value,
        'token' => Str::random(64),
        'invited_by' => $by?->getKey() ?? Str::uuid()->toString(),
        'accepted_at' => $acceptedAt,
    ]);
}

/**
 * Boot the provider against a registry of our own and hand back the declaration.
 *
 * The bind is not ceremony: beam-core BINDS `ParticleResourceRegistry` in its register phase and this
 * harness does not boot beam-core, so `bootFrameResources()`'s `bound()` guard is FALSE here — the
 * structural "a host without beam's particle registry gets nothing rather than a fatal" arm. Without the
 * bind this reads an empty room, which is the failure mode `TenantResourceTest` records having been
 * bitten by twice.
 */
function tenantInvitationsResource(): Splicewire\Beam\Particle\ParticleResource
{
    app()->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    $provider = new BeamTenancyServiceProvider(app());
    $provider->register();
    $provider->boot();

    return app(ParticleResourceRegistry::class)->get('tenant-invitations');
}

it('registers tenant-invitations onto the particle registry, under a key beam-accounts does not own', function () {
    $resource = tenantInvitationsResource();

    // The key is the whole point: `invitations` is beam-accounts' own, on a different model and table,
    // and the registry is last-wins by key. A shared key would make an operation's subject a fact about
    // provider order rather than about the declaration.
    expect($resource->key)->toBe('tenant-invitations')
        ->and($resource->modelClass())->toBe(TenantInvitation::class)
        ->and($resource->data)->toBe(InvitationData::class)
        // Not framed: this host's Frame manifest already serves this model under `invitations`.
        ->and($resource->isFramed())->toBeFalse()
        // `filterable` DEFAULTS to true, and a filterable resource with no data-filters registration
        // throws on its first index request. This assertion is the guard on that default.
        ->and($resource->filterable)->toBeFalse()
        ->and($resource->readOnly)->toBeTrue()
        ->and($resource->scope)->not->toBeNull();
});

it('scopes to the current tenant and to PENDING invitations — the gate the operations used to hand-roll', function () {
    $tenant = tap(Tenant::create(['id' => 'acme', 'name' => 'Acme', 'slug' => 'acme']), fn () => null);
    $other = Tenant::create(['id' => 'other', 'name' => 'Other', 'slug' => 'other']);

    $pending = inviteTo(Tenant::find('acme'), 'pending@example.test');
    inviteTo(Tenant::find('acme'), 'accepted@example.test', acceptedAt: now()->toDateTimeString());
    inviteTo($other, 'foreign@example.test');

    $resource = tenantInvitationsResource();

    // `tenant()` is null outside an initialized tenancy, and the scope then narrows to nothing rather
    // than to everything — the direction that matters, since this closure IS the read gate.
    expect(($resource->scope)(TenantInvitation::query())->count())->toBe(0);

    // The harness does not boot stancl's own provider, so `tenancy()->initialize()` leaves `tenant()`
    // null. Bind the contract the helper resolves — the same instance the flagship's own invitation tests
    // seat, so the scope is exercised through exactly the seam a request uses.
    app()->instance(TenantContract::class, Tenant::find('acme'));

    $rows = ($resource->scope)(TenantInvitation::query())->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->getKey())->toBe($pending->getKey());

    app()->forgetInstance(TenantContract::class);
});

it('DELIVERABLE: manageTenantInvitations re-subjects beam-accounts manageInvitations onto the invitation', function () {
    // Registered with the one line beam-accounts' provider runs; this harness does not boot it.
    Gate::define('manageInvitations', [MembershipPolicy::class, 'manageInvitations']);

    app(DemoTenantSeeder::class)->run();
    $tenant = Tenant::find(config('beam.tenancy.demo.tenant.slug'));

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();
    $admin = User::where('email', BeamDemo::email(Role::Admin->value))->firstOrFail();
    $member = User::where('email', BeamDemo::email(Role::Member->value))->firstOrFail();
    $outsider = User::create(['name' => 'Outsider', 'email' => 'outsider@example.test']);

    // Precondition: no central grant exists anywhere, so the seat is the only signal.
    expect(Schema::hasTable('roles'))->toBeFalse()
        ->and(Schema::hasTable('permissions'))->toBeFalse();

    $invitation = inviteTo($tenant, 'invitee@example.test', $owner);

    // The delegation, asserted in BOTH directions and against the tenant-subject answer it re-subjects:
    // same decision, same audience (owner|admin), a different subject.
    foreach ([[$owner, true], [$admin, true], [$member, false], [$outsider, false]] as [$actor, $expected]) {
        expect(Gate::forUser($actor)->allows('manageTenantInvitations', $invitation))->toBe($expected)
            ->and(Gate::forUser($actor)->allows('manageInvitations', $tenant))->toBe($expected);
    }

    // A guest is denied, and denied without reaching the policy's non-nullable team argument.
    expect(Gate::forUser(null)->allows('manageTenantInvitations', $invitation))->toBeFalse();
});

it('denies rather than fatals when the invitation names a tenant that is gone', function () {
    Gate::define('manageInvitations', [MembershipPolicy::class, 'manageInvitations']);

    app(DemoTenantSeeder::class)->run();
    $tenant = Tenant::find(config('beam.tenancy.demo.tenant.slug'));
    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    $invitation = inviteTo($tenant, 'orphan@example.test', $owner);
    $invitation->tenant_id = 'no-such-tenant';
    $invitation->setRelation('tenant', null);

    // `MembershipPolicy::manageInvitations()` type-hints a non-nullable `TeamContract`, so an orphan
    // invitation would TypeError rather than deny if the ability did not guard the relation first.
    expect(Gate::forUser($owner)->allows('manageTenantInvitations', $invitation))->toBeFalse();
});
