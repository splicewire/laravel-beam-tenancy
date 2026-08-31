<?php

use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Tenancy\MachineIdentity\MachineIdentityKind;
use Splicewire\Beam\Tenancy\MachineIdentity\MachineIdentityKindRegistry;

/**
 * The kernel half: that `beam.tenancy.machine-identity.kinds` is a real declared popcorn registry and
 * not an enum wearing a registry's clothes.
 *
 * The point of the registry is to REMOVE a ceiling. `service` was a machine axis crammed into
 * `tenant_users.role`, a closed human vocabulary that could not grow to hold it; replacing it with a
 * PHP enum would rebuild the same ceiling one table over, where tower could not add `broker` without
 * editing this package. So "it is open, and three tiers can write to it" is the claim, and these
 * tests are what make it fail loudly if someone later swaps the store for something closed.
 */

/**
 * ⚠️ THE TRIPWIRE (registry-kernel 27 D3), and it must stay first.
 *
 * A harness that omits `PopcornServiceProvider` gets a fresh `RegistryIndex` per `make()`, so every
 * `describe()` lands on a throwaway and every assertion below passes VACUOUSLY over an empty index.
 * This estate's signature defect is an instrument that reports success by not running; this is the
 * one assertion that can see it here.
 */
it('shares one registry index singleton', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('conforms to the kernel contract', function () {
    expect(app(MachineIdentityKindRegistry::class))->toBeInstanceOf(Registry::class);
});

it('describes its root into the shared index', function () {
    $keys = array_map(strval(...), app(RegistryIndex::class)->keys());

    expect($keys)->toContain('beam.tenancy.machine-identity.kinds');
});

it('ships the two kinds beam-tenancy owns', function () {
    $registry = app(MachineIdentityKindRegistry::class);

    expect(array_keys($registry->all()))->toBe(['sync', 'system']);
    expect($registry->get('sync'))->toBeInstanceOf(MachineIdentityKind::class);
    expect($registry->get('sync')->label)->toBe('Sync');
});

/**
 * The abilities slot is MEASURED for both shipped kinds now — and this test is the gate the previous
 * assertion asked a filling pass to come through and say so at.
 *
 * ## The contract changed, because two different claims were spelled the same way
 *
 * The old shape typed `abilities` as `list<string>` defaulting to `[]` and documented `[]` as
 * *"unmeasured"*. That held only until a kind's measured answer was genuinely "needs nothing" — and
 * `system`'s is: `system@app.splicewire.com` holds zero tokens, zero roles, and is never an
 * authenticated principal. Under the old spelling, "we looked and it needs nothing" and "nobody has
 * looked" were the same value, so the honest answer was unrecordable.
 *
 * `null` is now the default and means UNMEASURED; `[]` means measured-and-needs-nothing.
 * `hasMeasuredAbilities()` is the discriminator, and `abilities()` returns `?array` rather than
 * coalescing — a `?? []` there would re-collapse the two states at the one seam that exists to keep
 * them apart.
 */
it('distinguishes an UNMEASURED ability slot from a measured empty one', function () {
    $unmeasured = new MachineIdentityKind(key: 'nobody-looked', label: 'Nobody Looked');
    $measuredEmpty = new MachineIdentityKind(key: 'needs-nothing', label: 'Needs Nothing', abilities: []);

    expect($unmeasured->abilities())->toBeNull()
        ->and($unmeasured->hasMeasuredAbilities())->toBeFalse()
        ->and($measuredEmpty->abilities())->toBe([])
        ->and($measuredEmpty->hasMeasuredAbilities())->toBeTrue();
});

it('declares MEASURED abilities for both kinds beam-tenancy ships', function () {
    $registry = app(MachineIdentityKindRegistry::class);

    // `sync` — every entry is a gate that actually reads it: `engine:consume` is the only coarse gate
    // on the loopback's two guarded routes; the `composition.*` four are what `EngineConsumerToken`
    // already derives for this exact principal; `fragment.*` covers the connector paths;
    // `manage-schemas` the satellite schema path. This replaced an implicit `['*']` on the
    // `splicewire-sync` token, which under the permission-cascade CredentialScope meant no scope
    // narrowing at all.
    expect($registry->get('sync')->abilities())->toBe([
        'engine:consume',
        'composition.view',
        'composition.create',
        'composition.update',
        'composition.delete',
        'fragment.view',
        'fragment.create',
        'fragment.update',
        'fragment.delete',
        'manage-schemas',
    ])->and($registry->get('sync')->hasMeasuredAbilities())->toBeTrue();

    // `system` — measured EMPTY, which is a real answer and not a hole. The pairing of these two
    // assertions is the whole point of the contract change: without `hasMeasuredAbilities()` the
    // line below would be indistinguishable from "nobody has looked at `system` yet".
    expect($registry->get('system')->abilities())->toBe([])
        ->and($registry->get('system')->hasMeasuredAbilities())->toBeTrue();
});

/**
 * The whole reason this is a registry: a tier that beam-tenancy does not know about — tower, with
 * `broker` — registers from its own provider.
 */
it('accepts a kind registered by another tier', function () {
    $registry = app(MachineIdentityKindRegistry::class);

    $registry->register(new MachineIdentityKind(key: 'broker', label: 'Broker'));

    expect($registry->has('broker'))->toBeTrue();
    expect($registry->get('broker')->label)->toBe('Broker');
});

/** Keys go relative in and absolute out (registry-kernel 20 D2). */
it('addresses its entries under its declared root', function () {
    $keys = array_map(strval(...), app(MachineIdentityKindRegistry::class)->keys());

    expect($keys)->toBe([
        'beam.tenancy.machine-identity.kinds.sync',
        'beam.tenancy.machine-identity.kinds.system',
    ]);
});

it('routes an absolute kind key back to the registry', function () {
    expect(app(RegistryIndex::class)->routeTo('beam.tenancy.machine-identity.kinds.sync'))
        ->toBe(app(MachineIdentityKindRegistry::class));
});

/**
 * ⚠️ `kind` is read from the DATABASE, so an unknown one must degrade, never throw.
 *
 * Popcorn's provenance rule: a key the code chose is a `resolve()`; a key from outside is a
 * `tryResolve()`. Getting this backwards makes a shared table un-shareable — one host registering a
 * new kind would start 500-ing every other host that has not heard of it.
 */
it('degrades to null on a kind this host has never heard of', function () {
    expect(app(MachineIdentityKindRegistry::class)->get('a-kind-from-some-other-host'))->toBeNull();
});

/** The host half arrives through the ConfigRegistrar, and the host is the last writer. */
it('merges the host config half and lets it supersede a package kind', function () {
    config(['beam.tenancy.machine_identity.kinds' => [
        'courier' => new MachineIdentityKind(key: 'courier', label: 'Courier'),
        'sync' => new MachineIdentityKind(key: 'sync', label: 'Host Sync'),
    ]]);

    // Forget the resolved singleton so the registrar re-reads the config just set.
    app()->forgetInstance(MachineIdentityKindRegistry::class);
    $registry = app(MachineIdentityKindRegistry::class);

    expect($registry->has('courier'))->toBeTrue();
    expect($registry->get('sync')->label)->toBe('Host Sync');
});
