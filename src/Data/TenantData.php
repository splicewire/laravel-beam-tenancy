<?php

namespace Splicewire\Beam\Tenancy\Data;

use Illuminate\Database\Eloquent\Model;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Tenancy\Tenant;

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
 * **The extension contract.** A host with richer tenant admin — plan, bill, entitlements, suspend,
 * scaffold packs — layers its OWN resource under this same `tenants` key and gates this one off
 * with `beam.tenancy.frame_resources.enabled=false`. `splicewire/tower` is the worked example, and
 * `splicewire-app` sets exactly that flag. Same mechanism `beam.accounts.frame_resources.enabled`
 * already gives the accounts resources.
 *
 * READ-ONLY through Frame (`readOnly: true` ⇒ store/update/destroy 405, detail read still serves):
 * tenants arrive by provisioning, never from an admin create form — provisioning knows how to mint
 * a schema, a domain and an owner, and a form does not.
 */
#[ParticleResource(
    key: 'tenants',
    model: Tenant::class,
    label: 'Tenants',
    group: 'Platform',
    icon: 'building',
    form: 'bare',
    readOnly: true,
)]
#[TypeScript]
class TenantData extends Data
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
        );
    }
}
