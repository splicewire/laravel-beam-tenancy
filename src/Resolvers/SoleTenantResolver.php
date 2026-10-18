<?php

namespace Splicewire\Beam\Tenancy\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Tenancy\Exceptions\SoleTenantNotIdentified;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;

/**
 * Identify the ONLY eligible tenant of a sole-tenant install (tower-is-splicewire D4′, the `sole` topology).
 *
 * The request names no tenant, and nothing on the request is consulted: not the host, the path, a header, the
 * authenticated seat or the session. Identity comes from the installation's own state: exactly one eligible tenant
 * exists, so it is the tenant. Eligible means every tenant except the designated system tenant
 * ({@see \Splicewire\Beam\Tenancy\Concerns\DesignatedSystemTenant}), which is infrastructure, not the install's tenant.
 *
 * It fails CLOSED. An empty installation and an ambiguous one (two or more eligible tenants) both refuse with
 * {@see SoleTenantNotIdentified}, never a guess. Picking "the first" of two would silently serve one tenant's data on
 * another's install, which is the failure this mode must not have.
 */
class SoleTenantResolver implements TenantResolver
{
    public function resolve(...$args): Tenant
    {
        /** @var class-string<Model&Tenant> $model */
        $model = config('tenancy.tenant_model');

        // System tenants are excluded IN THE QUERY, before the limit. Nothing guarantees there is only one: limiting first
        // and filtering after would let system rows fill the window and hide a second eligible tenant, turning an
        // ambiguous install into a wrong answer (orch-peer HIGH, 5fafc48d). Two eligible rows are then enough to tell
        // "exactly one" from "more than one".
        $query = $model::query();

        if (method_exists($model, 'systemRole')) {
            $role = $model::systemRole();
            $query->where(fn ($q) => $q->whereNull('data->role')->orWhere('data->role', '!=', $role));
        }

        $eligible = $query->orderBy($model::make()->getKeyName())->limit(2)->get();

        if ($eligible->count() === 1) {
            return $eligible->first();
        }

        throw $eligible->isEmpty()
            ? SoleTenantNotIdentified::empty()
            : SoleTenantNotIdentified::ambiguous();
    }
}
