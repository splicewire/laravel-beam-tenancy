<?php

namespace Splicewire\Beam\Tenancy\MachineIdentity;

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
 * The open registry of machine-identity kinds — `tenant_machine_identities.kind` → the
 * {@see MachineIdentityKind} that declares what it is and (eventually) what it may do.
 *
 * ## Why a registry and NOT an enum
 *
 * This whole change exists to remove a ceiling: `service` was a machine axis crammed into
 * `tenant_users.role`, a closed human vocabulary that could not grow to hold it. Replacing one
 * closed vocabulary with another closed vocabulary would re-create the ceiling one table over —
 * tower could not add `broker` without editing this package, and a host could not add its own kind
 * at all. So the kinds are a declared popcorn registry, and the tiers that own kinds ship them from
 * their own providers.
 *
 * ## Storage is a composed {@see BasicRegistry} FIELD, not a `ConfigRegistry` base
 *
 * The attribute shape below is copied from `laravel-beam-accounts`' `BundleRegistry` — **the
 * attribute, not its base class.** `ConfigRegistry` makes a config array literally BE the storage,
 * which suits a registry only ever written by a host's config file. This one is written from three
 * directions at once:
 *
 * - beam-tenancy ships `sync` and `system` from its own provider,
 * - tower ships `broker` from its provider,
 * - the host declares its own in `config('beam.tenancy.machine_identity.kinds')`.
 *
 * A config array as storage cannot express the first two — there is nowhere for a package to write
 * except by mutating another tier's config, which is exactly the coupling the registry removes. So
 * the store is a field, package registrations are ordinary `register()` calls, and the host half
 * arrives through a {@see \Rushing\Popcorn\Registries\Registrars\ConfigRegistrar} attached at boot.
 * `Filled`'s ordering does the rest: registrars fill at attach, hand-registration comes later, and
 * later wins under {@see OnDuplicate::Supersede} — so a host can override a package's kind by
 * declaring the same key, which is the correct precedence.
 *
 * ## ⚠️ `kind` comes from the DATABASE, so reads are `tryResolve()` — never `resolve()`
 *
 * Popcorn's provenance rule: a key the CODE chose is a `resolve()` (a miss is a bug and should
 * throw); a key that arrives from OUTSIDE is a `tryResolve()` (a miss is data, and throwing turns
 * another host's row into a 500). Every `kind` this registry is asked about was read off a
 * `tenant_machine_identities` row that some other tier wrote, so {@see get()} degrades to null and
 * the caller decides.
 *
 * That is not laxity — it is the same asymmetry `beam-lineage`'s `ProducerKindRegistry` documents:
 * a strict read makes a shared table un-shareable, because one host registering a new kind would
 * start 500-ing every other host that has not heard of it. The registry's own contract-level
 * {@see resolve()} still throws `RegistryMiss` like every conforming registry, for callers naming a
 * key they chose themselves.
 */
#[IsRegistry(
    root: 'beam.tenancy.machine-identity.kinds',
    of: 'machine identity kinds a tenant may hold',
    arity: RegistryArity::PickOne,
    entryType: MachineIdentityKind::class,
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
)]
class MachineIdentityKindRegistry implements Gated, Registry
{
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Register (or replace) a kind. Widened contravariantly from the contract so a caller may pass
     * the self-keying {@see MachineIdentityKind} alone — which is how every package-tier
     * registration reads, the key already being on the object.
     */
    public function register(RegistryKey|string|MachineIdentityKind $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof MachineIdentityKind) {
            $entry = $key;
            $key = $key->key;
        }

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    /** Attach a registrar — the seam the host's `ConfigRegistrar` arrives through. */
    public function attach(Registrar $registrar): void
    {
        $this->entries->attach($registrar);
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
    }

    /**
     * The declaration for a kind read off a database row, or null when this host has never heard of
     * it. `tryResolve()`, per the provenance rule in the class docblock — an unknown kind is a fact
     * about this host's composition, not a bug in the caller.
     */
    public function get(string $kind): ?MachineIdentityKind
    {
        $definition = $this->entries->tryResolve($kind);

        return $definition instanceof MachineIdentityKind ? $definition : null;
    }

    /**
     * Every registered kind, keyed as the registrant spelled it — rebuilt from `relativeKeys()`
     * because keys go relative in and absolute out (registry-kernel 20 D2), and a caller-facing map
     * wants the caller's spelling.
     *
     * @return array<string, MachineIdentityKind>
     */
    public function all(): array
    {
        $kinds = [];

        foreach ($this->entries->relativeKeys() as $key) {
            $kinds[$key] = $this->entries->resolve($key);
        }

        return $kinds;
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }
}
