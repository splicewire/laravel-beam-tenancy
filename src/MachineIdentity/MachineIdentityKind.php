<?php

namespace Splicewire\Beam\Tenancy\MachineIdentity;

/**
 * One declared kind of machine identity a tenant may hold — the entry type of
 * {@see MachineIdentityKindRegistry}.
 *
 * A kind is the machine axis's answer to what `role` was doing badly: it names WHAT a program is to
 * a tenant (`sync`, `system`, tower's `broker`) rather than ranking it against human seats. The
 * vocabulary is open because the tiers are open — beam-tenancy ships the two it owns, tower ships
 * `broker` from its own provider, and a host ships whatever it runs.
 *
 * ## ⚠️ `abilities` is a declared slot with no values yet, and that is deliberate
 *
 * The list is the eventual authorization payload: what a holder of this kind may do in the tenant.
 * A sibling pass is measuring what actually belongs in it — every existing machine credential's real
 * ability set, read off the estate rather than guessed. Until that lands the two kinds this package
 * ships declare an EMPTY list, and empty means *not yet measured*, not *nothing permitted*.
 *
 * **Do not invent ability strings to fill this in.** A plausible-looking ability string that nothing
 * enforces is worse than an empty list: the empty list is visibly unfinished, whereas a fabricated
 * one reads as a decision and will be trusted by the first reader who does not know it was a guess.
 * Nothing consults `abilities()` yet — the readers arrive with the values.
 */
class MachineIdentityKind
{
    /**
     * @param  string  $key  the `tenant_machine_identities.kind` discriminator — a bare key
     *                       (`sync`, `system`, `broker`), addressed under the registry's root
     * @param  string  $label  operator-facing name for the kind
     * @param  list<string>  $abilities  what a holder of this kind may do. EMPTY on both kinds this
     *                                   package ships — see the class docblock; a later pass fills
     *                                   it from measurement, and inventing values here pre-empts it
     * @param  string|null  $description  what this kind of machine is, for an operator reading a
     *                                    grant they did not create
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $abilities = [],
        public readonly ?string $description = null,
    ) {}

    /**
     * The abilities this kind declares.
     *
     * ⚠️ An empty array today means UNMEASURED, not "denies everything" — no caller may read a `[]`
     * here as an authorization answer until the sibling pass supplies real values. There is
     * deliberately no `permits()` helper yet, so that nothing can accidentally start enforcing an
     * empty list as a deny-all.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return $this->abilities;
    }
}
