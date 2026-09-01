<?php

namespace Splicewire\Beam\Tenancy\Provisioning;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The ordered steps a host runs when a tenant is created — declared once, read by every caller that
 * provisions.
 *
 * ## What this replaces
 *
 * Measured 2026-08-31. The same pipeline was written out by hand in three places:
 *
 * - `~/Herd/splicewire-app/app/Providers/TenancyServiceProvider.php:41-57` — 13 steps, queued.
 * - `splicewire/tower` `Provisioning/TenantProvisioning.php:110-122` — 12 steps, `dispatch_sync`,
 *   under the comment *"Keep this list in lockstep with that pipeline so a synchronous provision is
 *   a faithful mirror of the queued one — no missing step drift."*
 * - `~/Herd/standwell/app/Providers/TenancyServiceProvider.php:27-35` — five steps whose classes are
 *   app-local (`App\Jobs\Tenant\*`) and share NAMES with tower's, which is worse than differing.
 *
 * A comment instructing a human to keep two arrays identical is a registry that has not been written
 * yet. It had already failed: the two lists agree on membership 13/13 and disagree on ORDER —
 * the async pipeline runs `CreateTenantDomain` before `CreateDatabase` (deliberately: *"Central DB
 * only — no tenant DB dependency"*), and `retry()` runs it after. That particular inversion is benign
 * precisely because that step touches no tenant schema, but nothing checked that, and nothing would
 * have caught an inversion that was not benign.
 *
 * ## ⚠️ Membership and ORDER only — deliberately not dispatch
 *
 * The async host pipes these through a queued `JobPipeline`; `TenantProvisioning::retry()` runs them
 * with `dispatch_sync` and additionally skips a non-idempotent `CreateDatabase` when the schema
 * already exists. Both are correct for their caller. Folding dispatch into this registry would force
 * one of the two to lie about how it runs, so the entry is a plain ordered list of class-strings and
 * the caller keeps its own dispatch.
 *
 * ## Storage is a composed {@see BasicRegistry} FIELD, not a `ConfigRegistry` base
 *
 * Same reasoning as {@see \Splicewire\Beam\Tenancy\MachineIdentity\MachineIdentityKindRegistry}: this
 * is written from more than one direction — the engine tier registers the real pipeline from its own
 * provider, and a host supersedes it from config. A config array as storage cannot express the first,
 * because a package would have to mutate another tier's config to register anything.
 *
 * `OnDuplicate::Supersede` is what makes standwell's variant a *registration* rather than a fourth
 * copy: it declares the same key later and wins.
 *
 * ## Reads are `resolve()`, not `tryResolve()`
 *
 * Popcorn's provenance rule. A pipeline name is chosen by the CODE that provisions — it never arrives
 * off a database row or a request — so a miss is a bug in the caller and must throw. (Contrast
 * `MachineIdentityKindRegistry`, whose keys are read from `tenant_machine_identities.kind` and
 * therefore degrade to null, because throwing there would turn another host's row into a 500.)
 */
#[IsRegistry(
    root: 'beam.tenancy.provisioning.steps',
    of: 'named tenant-provisioning pipelines — each an ORDERED list of step class-strings a host runs when a tenant is created',
    arity: [RegistryArity::PickOne, RegistryArity::ComposeMany],
    entryType: 'list<class-string>',
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    note: 'The read is TWO steps: PickOne selects the named pipeline, then ComposeMany runs its '
        .'ordered steps. The steps have no keys of their own — the same step class may legitimately '
        .'appear twice — which is why the second step is a level of the arity and not a nested root. '
        .'`entryType` is a list rather than a class because an entry is the ordering, not an object. '
        .'Modelled on `rushing/laravel-pipeline-registry`, which expresses the identical shape for '
        .'Illuminate pipeline stages; this one holds queued Jobs and leaves dispatch to the caller.',
)]
class TenantProvisioningStepRegistry implements Gated, Registry
{
    private BasicRegistry $pipelines;

    public function __construct()
    {
        $this->pipelines = BasicRegistry::for($this);
    }

    /**
     * Register (or replace) a named pipeline from its ordered step list.
     *
     * The entry MUST be a list. Refused loudly rather than wrapped, on `PipelineRegistry`'s
     * precedent: a bare class-string would otherwise run, silently, as a one-step pipeline nobody
     * wrote — and a keyed map would offer an order that is the author's spelling rather than a
     * guarantee.
     *
     * @param  list<class-string>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if (! is_array($entry) || ! array_is_list($entry)) {
            throw new InvalidArgumentException(sprintf(
                'A `%s` entry is an ORDERED LIST of provisioning step class-strings; got %s for `%s`. '
                .'The steps have no keys — the order IS the contract, and a map does not carry one.',
                self::class,
                get_debug_type($entry),
                (string) $key,
            ));
        }

        $this->pipelines->register($key, $entry, $by, $ability);

        return $this;
    }

    /** Attach a registrar — the seam a host's `ConfigRegistrar` arrives through. */
    public function attach(Registrar $registrar): void
    {
        $this->pipelines->attach($registrar);
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->pipelines->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->pipelines->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->pipelines->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->pipelines->matches($key);
    }

    public function keys(): array
    {
        return $this->pipelines->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->pipelines->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->pipelines->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The ordered step class-strings for a named pipeline.
     *
     * Throws on a miss, per the provenance rule in the class docblock.
     *
     * @return list<class-string>
     */
    public function stepsFor(string $name): array
    {
        $steps = $this->pipelines->resolve($name);

        return is_array($steps) ? array_values($steps) : [];
    }

    /**
     * Every registered pipeline name, as the registrant spelled it — rebuilt from `relativeKeys()`
     * because keys go relative in and absolute out (registry-kernel 20 D2).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values($this->pipelines->relativeKeys());
    }
}
