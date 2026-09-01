<?php

use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Tenancy\Provisioning\TenantProvisioningStepRegistry;

/**
 * The seam that stops a tenant-provisioning pipeline being a literal array in three places.
 *
 * Measured 2026-08-31, which is why this exists. `tower/src/Provisioning/TenantProvisioning.php:110`
 * carries a 12-entry array under the comment *"Keep this list in lockstep with that pipeline so a
 * synchronous provision is a faithful mirror of the queued one — no missing step drift."* The other
 * list is `~/Herd/splicewire-app/app/Providers/TenancyServiceProvider.php:41-57` (13 entries), and a
 * THIRD lives at `~/Herd/standwell` with five app-local steps of its own. A comment asking a human to
 * keep two arrays identical is the thing a registry replaces.
 *
 * ⚠️ The registry owns MEMBERSHIP and ORDER only. It does not dispatch — the async host pipes the
 * steps through a queued JobPipeline and `retry()` runs them with `dispatch_sync`, and both are
 * correct. Folding dispatch in here would force one of the two callers to lie about how it runs.
 */

/**
 * ⚠️ THE TRIPWIRE (registry-kernel 27 D3), and it must stay first.
 *
 * A harness that omits `PopcornServiceProvider` gets a fresh `RegistryIndex` per `make()`, so every
 * registration lands on a throwaway and every assertion below passes VACUOUSLY over an empty index.
 * This estate's signature defect is an instrument that reports success by not running.
 */
it('shares one registry index singleton', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

/**
 * ⚠️ The second tripwire, and it catches a different failure from the first.
 *
 * An UNBOUND registry is still auto-resolvable, so `app()` returns a fresh empty instance per call:
 * the engine tier registers its pipeline into one object and every caller reads another. Nothing
 * errors — provisioning just runs zero steps. Asserting identity is the one-line probe AGENTS.md
 * prescribes for exactly this, and it fails the moment someone drops the singleton binding.
 */
it('is bound as a singleton, not merely auto-resolvable', function () {
    expect(app(TenantProvisioningStepRegistry::class))->toBe(app(TenantProvisioningStepRegistry::class));
});

it('conforms to the kernel contract', function () {
    expect(app(TenantProvisioningStepRegistry::class))->toBeInstanceOf(Registry::class);
});

it('returns the steps in the order they were registered', function () {
    $registry = new TenantProvisioningStepRegistry;
    $registry->register('default', ['StepA', 'StepB', 'StepC']);

    // Order IS the contract here — a provisioning pipeline that runs its steps in a different order
    // is a different pipeline, which is exactly the drift the two hand-kept arrays already carry.
    expect($registry->stepsFor('default'))->toBe(['StepA', 'StepB', 'StepC']);
});

it('refuses an entry that is not an ordered list, rather than running a pipeline nobody wrote', function () {
    $registry = new TenantProvisioningStepRegistry;

    // PipelineRegistry's precedent: a bare class-string would otherwise run, silently, as a
    // one-step pipeline. Refused loudly instead of wrapped.
    expect(fn () => $registry->register('default', 'StepA'))
        ->toThrow(InvalidArgumentException::class);

    // A keyed map is not a list: its order is the author's spelling, not a guarantee.
    expect(fn () => $registry->register('default', ['first' => 'StepA']))
        ->toThrow(InvalidArgumentException::class);
});

it('lets a later registration supersede an earlier one, which is how a host overrides a package', function () {
    $registry = new TenantProvisioningStepRegistry;
    $registry->register('default', ['StepA', 'StepB']);
    $registry->register('default', ['HostStep']);

    // `~/Herd/standwell` runs five app-local steps under the same intent. Supersession is what makes
    // that a registration instead of a fourth copy of the array.
    expect($registry->stepsFor('default'))->toBe(['HostStep']);
});

it('throws on an unknown pipeline name, because the caller chose that key', function () {
    $registry = new TenantProvisioningStepRegistry;

    // Popcorn's provenance rule: a key the CODE names is a `resolve()` — a miss is a bug and must
    // throw. (Contrast MachineIdentityKindRegistry, whose keys arrive off a database row and so
    // degrade to null.) Nothing reads a provisioning pipeline name out of user data.
    // ⚠️ NOT `toThrow(Throwable::class)`. Pest reads a string argument as an expected MESSAGE unless
    // `class_exists()` says otherwise, and `Throwable` is an INTERFACE — so that spelling silently
    // asserts "the message contains the word Throwable" and passes only by accident. Name the class.
    expect(fn () => $registry->stepsFor('nope'))->toThrow(RegistryMiss::class);
});

it('reports whether a pipeline is registered without resolving it', function () {
    $registry = new TenantProvisioningStepRegistry;
    $registry->register('default', ['StepA']);

    expect($registry->has('default'))->toBeTrue()
        ->and($registry->has('nope'))->toBeFalse();
});
