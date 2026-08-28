<?php

namespace Splicewire\Beam\Tenancy\Data;

use Illuminate\Database\Eloquent\Model;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Workflows\Data\StatusEventData;

/**
 * The neutral tenants-admin LIST + DETAIL resource.
 *
 * A host that wanted a plain list of its tenants used to have to install `splicewire/tower` and
 * take on the whole Subscription ⊕ Bill enrichment with it. This is the half that only needs
 * `Tenant`, homed in the package that owns the model.
 *
 * **It carries no commerce field, and that is the design rather than an omission.**
 * `laravel-beam-commerce` REQUIRES `laravel-beam-tenancy`, so plan, bill status, bill total and
 * entitlements — every one of them a cross-model Subscription ⊕ Bill fold — structurally cannot
 * descend here; naming a single `Splicewire\Beam\Commerce\*` symbol would close a dependency cycle.
 * A seam guard in this package's suite asserts that mechanically.
 *
 * **Model-backed, not source-backed.** Tower's variant is a `source:` union *solely* because that
 * batch-keyed commerce enrichment cannot come from `Data::from(Tenant)`. Drop the enrichment and
 * the reason for the union goes with it, so this one is a plain attribute declaration — simpler
 * than its tower counterpart by construction, not by omission.
 *
 * **The extension contract: CONTRIBUTE, DON'T OVERWRITE.**
 * The old contract said a host with richer tenant admin layers its OWN resource under this same
 * `tenants` key and wins, because `ParticleResourceRegistry` keys by resource key and the LAST
 * registration wins. That was never a layering contract — the winner replaces the loser wholesale,
 * which is exactly how `splicewire/tower`'s `tenants` reached 22 props against this class's 9. It is
 * the defect the particle-contribution-seam map exists to retire, not a mechanism to build on. (Nor
 * was the race ever actually run: this declaration rode a dead `afterResolving` hook, so tower's was
 * the only declaration for the key that had ever existed — map tickets 02 and 07.)
 *
 * The contract in force is ticket 04's: **a package that owns a concern declares that concern's own
 * named sub-projection** against this key, through beam's `ResourceContributionRegistry` (landed by
 * ticket 15 — named here in prose deliberately, since this package must never take a hard reference on
 * it). This class names no contributor and pre-declares no hook — the contributor reaches down, because it is the one that
 * already depends on this package. `laravel-beam-commerce` attaches plan / bill / entitlements that
 * way under the `commerce` key, which is what lets a beam host with commerce but no tower see commerce
 * data at all.
 *
 * Two consequences of that seam are worth knowing while reading this class, because neither is visible
 * from here:
 *   - the contributed key is **absent** on a host without the contributing package, and **present
 *     holding null** when the contributor ran and had nothing for this record. A caller can tell "not
 *     installed" from "not on a plan"; this class does nothing to make that true, the seam does.
 *   - commerce attaches its own `subscription`/`bills` relations onto `Tenant` from ABOVE
 *     (`resolveRelationUsing`), so the slice batches. That is why this class can carry no commerce
 *     property and still cost nothing extra per row.
 *
 * So this declaration is registered UNCONDITIONALLY (ticket 14), and nothing overwrites it. There is
 * no off-switch — the `beam.tenancy.frame_resources.enabled` flag that used to exist (and its
 * `beam.accounts.*` twin) is deleted.
 *
 * **What it carries is everything `Tenant` itself knows, and nothing else.** Ticket 03 §A1 read tower's
 * 22 prop by prop: 8 of the 14 extras are backed by `Tenant` alone and belong here (a package widening
 * its own declaration of its own model is not a seam question at all); 5 are commerce, and arrive as a
 * contribution; 1 (`period`) is a request facet echoed back, not projection data.
 *
 * READ-ONLY through Frame (`readOnly: true` ⇒ store/update/destroy 405, detail read still serves):
 * tenants arrive by provisioning, never from an admin create form — provisioning knows how to mint
 * a schema, a domain and an owner, and a form does not.
 */
#[ParticleResource(
    key: 'tenants',
    backing: Tenant::class,
    // What makes the widening free instead of a per-row cost. `primaryHost` folds the domains, and
    // `statuses`/`isBusy`/`isStalled` all resolve off the Display timeline — every one of them a query
    // per row until the relation is eager-loaded here (ticket 03 §A3, ticket 05's `ParticleListQuery`).
    includes: ['domains', 'statusEvents'],
    // No data-filters query is registered for `tenants` anywhere in the estate, and a `filterable: true`
    // index routes through `ParticleHydrator::query()`, whose shipped default
    // (`PayloadParticleReader::query()`) THROWS. The declaration's default is true, so this line is what
    // stops a bare beam host's tenants list from 500-ing the moment the declaration goes live — an
    // invisible defect for as long as it sat unregistered. Tower's declaration says the same thing.
    filterable: false,
    label: 'Tenants',
    group: 'Platform',
    icon: 'building',
    form: 'bare',
    // Nav placement, descended from tower's declaration with the teardown (ticket 20 step 2). These are
    // not projection — they say where the resource sits in the admin nav — and unlike `editData` a host
    // that disagrees keeps a working escape hatch: nav is read off `definitions($realm)`, the manifest
    // arm, which is the one `RealmResourceRegistry::apply()` actually overlays. (The resource path
    // passes `$realm === null` and gets the base back untouched, which is why `editData` below could NOT
    // be re-homed that way — a trap this map has fallen into three times.)
    section: 'platform',
    navOrder: 1,
    routeName: 'tenants.index',
    // The create-SCHEMA escape hatch (ADR-0156 §83): the schema endpoint emits the create form the
    // admin UI renders and SUBMITS to the REST provisioning endpoint — a schema surface, not a Frame
    // write path, which is why it coexists with `readOnly: true`.
    //
    // ⚠️ Narrowed on the way down: tower's version carried `planSlug`/`commitmentMonths` (beam-commerce)
    // and `scaffoldPackSlugs` (tower). See {@see CreateTenantData} for why those three are lost rather
    // than contributed.
    editData: CreateTenantData::class,
    readOnly: true,
)]
#[TypeScript]
class TenantData extends BeamData
{
    public function __construct(
        #[NotInList]
        public string $id,
        #[Column(label: 'Tenant', sort: 0)]
        public string $name,
        #[Column(label: 'Slug', sort: 1)]
        public ?string $slug,
        #[Column(label: 'Status', sort: 2)]
        public ?string $provisioningStatus,
        // Orthogonal to provisioningStatus, and deliberately its own column: an operator needs to
        // tell a tenant that is deliberately inactive from one that is broken, and a single
        // conflated status field cannot say which.
        #[Column(label: 'Suspended', sort: 3)]
        public bool $suspended,
        #[Column(label: 'Owner', sort: 4)]
        public ?string $ownerEmail,
        #[Column(label: 'Created', sort: 5)]
        public ?string $createdAt,
        /** @var list<string> */
        #[NotInList]
        public array $domains = [],
        #[NotInList]
        public ?string $suspendedAt = null,
        // ── The 8 owner-local props (ticket 03 §A1). Every one is backed by `Tenant` itself; none of
        // them is a list column, so they ride the detail read and the manifest stays six columns wide.
        /** The broker this is a Brokered Tenant of; null = a direct tenant (ADR-0043). */
        #[NotInList]
        public ?string $parentTenantId = null,
        /** @var array{preferred_model?: string, provider?: string}|null */
        #[NotInList]
        public ?array $llmConfig = null,
        /** @var list<string> */
        #[NotInList]
        public array $scaffoldPackSlugs = [],
        /** The channel the live ADR-0160 status push is subscribed on. */
        #[NotInList]
        public ?string $statusChannel = null,
        /** The absolute host the tenant is reached at — folded from `domains`, never rebuilt by a caller. */
        #[NotInList]
        public ?string $primaryHost = null,
        /** @var list<StatusEventData> */
        #[NotInList]
        public array $statuses = [],
        /** Provisioning or pack-apply work still in flight — the UI's subscribe/poll stop condition. */
        #[NotInList]
        public bool $isBusy = false,
        /** A run gone quiet without a terminal status — drives "provisioning stalled — retry". */
        #[NotInList]
        public bool $isStalled = false,
    ) {}

    public static function project(Model $tenant): self
    {
        return new self(
            id: (string) $tenant->getKey(),
            name: (string) $tenant->name,
            slug: $tenant->slug,
            provisioningStatus: $tenant->provisioning_status,
            suspended: $tenant->suspended_at !== null,
            ownerEmail: $tenant->owner_email,
            createdAt: $tenant->created_at?->toIso8601String(),
            domains: $tenant->domains->pluck('domain')->all(),
            suspendedAt: $tenant->suspended_at,
            parentTenantId: $tenant->parent_tenant_id,
            llmConfig: $tenant->llm_config ?? null,
            scaffoldPackSlugs: $tenant->scaffold_pack_slugs ?? [],
            statusChannel: $tenant->status_channel,
            primaryHost: $tenant->primaryHost(),
            // Plain arrays, not `Lazy`. Tower's variant wrapped these two in `Lazy::create()`, and
            // nothing ever called `->include()` on them — so a Lazy prop here would be a field that
            // never reaches the wire. The declared `statusEvents` include is what makes materializing
            // them cost nothing, which is the reason the laziness existed in the first place.
            statuses: $tenant->statusTimeline()
                ->map(fn ($activity) => StatusEventData::fromActivity($activity))
                ->values()
                ->all(),
            isBusy: $tenant->isBusy(),
            isStalled: $tenant->provisioningIsStalled(),
        );
    }
}
