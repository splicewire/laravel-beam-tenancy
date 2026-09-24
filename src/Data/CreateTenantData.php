<?php

namespace Splicewire\Beam\Tenancy\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Tenancy\TenantStorage;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

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
 *
 * `storage` (pooled-storage ticket 04) is the one field ADDED since, and it passes the same test the
 * three failed: it is a tenancy-core concept — which of the two creatable {@see TenantStorage} states
 * the tenant lands in — naming no plan. The plan → storage mapping stays upward, behind
 * `beam.tenancy.pooled.storage_resolver`. Null means "let the host decide" (resolver, then the
 * configured default); `isolated` is rejected because isolation only ever arrives by migration.
 */
#[TypeScript]
class CreateTenantData extends BeamData implements MapsToModelAttributes
{
    public function __construct(
        public string $slug,
        public ?string $name = null,
        public ?string $ownerEmail = null,
        /**
         * Declared as a validation ATTRIBUTE, not a `rules()` entry, because the attribute is the one
         * form both readers see: `validateAndCreate()` (the particle write path, 422 on anything else)
         * and the schema generator, which emits it as the create form's `enum` — so the operator picks
         * pooled / schema / (host decides) rather than typing into a free-text box. A `rules()` method
         * reached only the first; the form it left behind was a text input for a two-value choice.
         * The cases must equal {@see TenantStorage::creatable()} (a test pins it); `DecideStorage`
         * rejects an unknown or `isolated` request a second time, so a caller that skips validation
         * still cannot land one.
         */
        #[In(TenantStorage::Pooled, TenantStorage::Schema)]
        public ?string $storage = null,
    ) {}

    /**
     * The declared write map (particle-doctrine: a class in an `editData:` slot declares it, never relies
     * on the snake-case fallback). It matters here for one field: `storage` is a REQUEST, and the tenant
     * attribute it lands on is `requested_storage` — consumed once by `DecideStorage` — not `storage`,
     * which is `Tenant::storage()`, the derived STATE. The fallback mapper would have written the wrong
     * key and the request would have vanished into the data blob with a 200. The slug doubles as the id
     * because `tenancy.id_generator` is null estate-wide (slugs are ids).
     *
     * Create path, two-state: a null is omitted, never written — there is nothing to clear on a row
     * that does not exist yet. `name` is left to the caller's default (tower ucfirsts the slug).
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return array_filter([
            'id' => $this->slug,
            'slug' => $this->slug,
            'name' => $this->name,
            'owner_email' => $this->ownerEmail,
            'requested_storage' => $this->storage,
        ], fn ($value) => $value !== null);
    }
}
