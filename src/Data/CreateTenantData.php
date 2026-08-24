<?php

namespace Splicewire\Beam\Tenancy\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * The CREATE input shape for `tenants` — the `editData` escape hatch (ADR-0156 §83) that drives the
 * Frame create schema-form, descended from `splicewire/tower` with the particle-contribution-seam
 * teardown (ticket 20, decision of record ticket 17 §A5).
 *
 * **It is NARROWER than the class it replaces, and the three missing fields are the point.**
 * Tower's `CreateTenantData` carried six props with THREE owners: the tenancy core (`slug`, `name`,
 * `ownerEmail`), beam-commerce (`planSlug`, `commitmentMonths`) and tower itself
 * (`scaffoldPackSlugs`, a `scaffold-packs` resource-ref). It fused them for exactly one reason — a
 * resource declaration carries a SINGLE `editData` slot — which makes it the god-projection's third
 * instance, after `tenants` (last-registration-wins on a resource key) and `TowerAuthUserData`
 * (last-bind-wins on a container binding).
 *
 * ⚠️ `ComposeMany` does NOT cure this one. Beam's `ResourceContributionRegistry` folds onto an
 * already-projected READ row and `ResourceContribution` carries only `includes` and `value` — there is
 * no write arm at all, so a contributed `editData` slice has nowhere to land and nobody to validate or
 * persist it. Building one is a WRITE-side seam question, past this map's destination, and it sits in
 * the map's fog rather than in this class.
 *
 * So the three fields are LOST, knowingly: a host's operator create-tenant form no longer selects a
 * plan or a scaffold pack, and a tenant is created plan-less and subscribed separately. That loss is
 * deliberate evidence — if it bites, the write-side seam graduates out of fog. **Do not quietly re-add
 * them here**; a `planSlug` in this class would name a beam-commerce concept inside beam-tenancy and
 * close the same dependency cycle the read-side seam guard exists to prevent.
 */
#[TypeScript]
class CreateTenantData extends Data
{
    public function __construct(
        public string $slug,
        public ?string $name = null,
        public ?string $ownerEmail = null,
    ) {}
}
