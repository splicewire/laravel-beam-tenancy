<?php

namespace Splicewire\Beam\Tenancy\Summary;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryFigureData;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ScopedIndexQuery;
use Splicewire\Beam\Tenancy\TenantProvisioningStatus;

/**
 * The summary provider for the `tenants` particle resource — the five figures its dashboard tile shows,
 * grouped by provisioning state (realm-dashboards ticket 06).
 *
 * ## Why it lives HERE and not in `splicewire/tower`
 *
 * The figures are a fact about `Tenant` alone: `provisioning_status` and `suspended_at` are this
 * package's own columns, and grouping a roster by them needs nothing tower knows. This package may not
 * depend on tower (`composer.json` `package-topology.mustNotRequire`), and a tower-side overlay through
 * `RealmResourceRegistry` cannot carry a provider slot anyway (`RealmResourceOverride` has no
 * `summaryProvider` field — the slot is preserved by overrides, never set by them). So the owning package
 * declares it, on the declaration that owns the key: `summaryProvider:` on `TenantData`. A beam host
 * with tenancy and no tower gets the same tile.
 *
 * ## The scope-leak rule
 *
 * Every figure is counted off {@see ScopedIndexQuery::forDefinition()} — the SAME owner-scoped,
 * `filter[...]`-aware, `scope()`-gated builder the Frame index reads — never the bare `Tenant::query()`.
 * Today the neutral `tenants` declaration carries no `scope` closure, so on a central realm this equals
 * the whole roster, which is what an operator's tile should say; the day a host declares one, the tile
 * narrows with the list rather than silently keeping the global total.
 *
 * ## Why the grouping is in memory
 *
 * `provisioning_status` and `suspended_at` are stancl VIRTUAL columns — folded into the `data` JSON,
 * not real columns — so a SQL `GROUP BY` cannot see them. One scoped load and a `countBy` is the same
 * shape tower's operator dashboard has always used, and for the same reason: an operator's roster is
 * tens to hundreds of rows. The declared includes (`domains`, `statusEvents`) are dropped for this read
 * because they exist to make the row PROJECTION free, and a count projects nothing.
 *
 * `provisioning` is Pending ⊕ Provisioning — the two in-flight states an operator reads as "still
 * arriving"; `suspended` is orthogonal to the provisioning axis (a suspended tenant is usually `active`),
 * which is why it is its own figure rather than a fifth status and why the five do not sum to `total`.
 */
class TenantsSummaryProvider implements ResourceSummaryProvider
{
    public function __construct(private ScopedIndexQuery $query) {}

    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        if (! $this->query->queryable($resource)) {
            return null;
        }

        /** @var Builder $rows */
        $rows = $this->query->forDefinition($resource);

        $tenants = $rows->setEagerLoads([])->get();
        $byStatus = $tenants->countBy(fn (Model $tenant): string => (string) $tenant->provisioning_status);
        $count = fn (TenantProvisioningStatus $status): int => (int) ($byStatus[$status->value] ?? 0);

        // `resolvedLabel()` and not `$resource->nav->label`: a host that overlays the label away would
        // otherwise tile an empty string, and the definition owns that fallback.
        $label = $resource->resolvedLabel();

        return new SummaryResponseData(
            key: $resource->key,
            label: $label,
            icon: $resource->nav->icon,
            figures: [
                new SummaryFigureData(key: 'total', label: $label, value: $tenants->count()),
                new SummaryFigureData(key: 'active', label: 'Active', value: $count(TenantProvisioningStatus::Active), tone: 'active'),
                new SummaryFigureData(
                    key: 'provisioning',
                    label: 'Provisioning',
                    value: $count(TenantProvisioningStatus::Pending) + $count(TenantProvisioningStatus::Provisioning),
                    tone: 'busy',
                ),
                new SummaryFigureData(
                    key: 'suspended',
                    label: 'Suspended',
                    value: $tenants->filter(fn (Model $tenant): bool => $tenant->suspended_at !== null)->count(),
                    tone: 'warn',
                ),
                new SummaryFigureData(key: 'failed', label: 'Failed', value: $count(TenantProvisioningStatus::Failed), tone: 'danger'),
            ],
            overview: null,
        );
    }
}
