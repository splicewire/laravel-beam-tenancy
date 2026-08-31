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
 * ⚠️ The abilities slot exists and is EMPTY, and empty means UNMEASURED.
 *
 * A sibling pass is measuring what belongs here from the estate's real credentials. This assertion
 * is deliberately pinned to `[]` so that a later pass filling it has to come through this test and
 * say so — and so that nobody quietly invents plausible ability strings in the meantime, which would
 * read as a decision and be trusted.
 */
it('declares an abilities slot that is empty pending measurement', function () {
    $registry = app(MachineIdentityKindRegistry::class);

    expect($registry->get('sync')->abilities())->toBe([]);
    expect($registry->get('system')->abilities())->toBe([]);
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
