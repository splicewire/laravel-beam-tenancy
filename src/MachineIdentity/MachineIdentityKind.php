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
 * ## ⚠️ `abilities` has THREE states, not two — and the third is why this is nullable
 *
 * The original shape typed `abilities` as `list<string>` defaulting to `[]`, and documented the
 * empty list as meaning *"not yet measured"*. That worked exactly as long as no kind's measured
 * answer was genuinely "needs nothing" — and `system`'s is. Measured on the flagship: the System
 * Account holds **zero tokens, zero roles, and is never an authenticated principal** — it exists
 * only as an owner-of-record id on machine-written rows. Its correct ability list is empty, and
 * under the old contract that was indistinguishable from "nobody has looked yet".
 *
 * So the states are now distinct by TYPE rather than by convention:
 *
 * | value | meaning |
 * |---|---|
 * | `null` (the default) | **UNMEASURED.** Nobody has established what this kind may do. Not a deny-all — a caller that reads this as an authorization answer is reading a hole as a decision. |
 * | `[]` | **MEASURED: needs nothing.** Somebody looked, and the answer is that this kind authenticates nothing and requires no ability. A real, defensible deny-all. |
 * | a non-empty list | measured, and these are the abilities. |
 *
 * {@see hasMeasuredAbilities()} is the discriminator, and {@see abilities()} deliberately returns
 * `?array` rather than coalescing to `[]` — coalescing would re-collapse the two states at the exact
 * seam that exists to keep them apart. A caller that wants a list must decide, in its own code, what
 * an unmeasured kind means to it.
 *
 * **Still do not invent ability strings.** A plausible-looking ability string that nothing enforces
 * is worse than `null`: `null` is visibly unfinished, whereas a fabricated list reads as a decision
 * and will be trusted by the first reader who does not know it was a guess. Declare `[]` only when
 * you have actually measured that nothing is needed, and say where you measured it.
 */
class MachineIdentityKind
{
    /**
     * @param  string  $key  the `tenant_machine_identities.kind` discriminator — a bare key
     *                       (`sync`, `system`, `broker`), addressed under the registry's root
     * @param  string  $label  operator-facing name for the kind
     * @param  list<string>|null  $abilities  what a holder of this kind may do. `null` (the default)
     *                                        means UNMEASURED; `[]` means measured and needing
     *                                        nothing. See the class docblock — the two are not the
     *                                        same claim and must not be spelled the same way
     * @param  string|null  $description  what this kind of machine is, for an operator reading a
     *                                    grant they did not create
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?array $abilities = null,
        public readonly ?string $description = null,
    ) {}

    /**
     * The abilities this kind declares, or `null` when nobody has measured them.
     *
     * ⚠️ Returns `?array` on purpose. `[] ` and `null` are different answers — "measured: needs
     * nothing" and "unmeasured" — and a `?? []` here would erase that distinction for every caller
     * at once. Branch on {@see hasMeasuredAbilities()} first.
     *
     * There is still deliberately no `permits()` helper: enforcement wants a decision about what an
     * unmeasured kind means, and that decision belongs to the enforcing tier, not here.
     *
     * @return list<string>|null
     */
    public function abilities(): ?array
    {
        return $this->abilities;
    }

    /**
     * Whether anyone has established this kind's ability set.
     *
     * True for a measured empty list as much as for a measured non-empty one — that is the whole
     * point of the split.
     */
    public function hasMeasuredAbilities(): bool
    {
        return $this->abilities !== null;
    }
}
