<?php

namespace Splicewire\Beam\Tenancy;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Scope spatie/laravel-permission to the active tenant.
 *
 * ⚠️ THE HOST CHOOSES THE TOPOLOGY, AND BOTH ARE LIVE IN THIS ESTATE. Do not read
 * either one out of this class as universal — this bootstrapper is deliberately
 * correct under both, and an earlier version of this docblock asserted (2) as fact,
 * which is false at the host that carries the most authorization data.
 *
 *  (A) CENTRAL TABLES + `team_id` DISCRIMINATOR. The host binds Role/Permission
 *      models pinned to the central connection and sets `permission.teams => true`.
 *      One `roles`/`permissions`/`model_has_roles` set lives in the central schema;
 *      rows are separated by the `team_foreign_key` column holding the tenant key.
 *      Measured at ~/Herd/splicewire-app 2026-08-30: 34 of 36 `public.model_has_roles`
 *      rows are TenantUser→Admin keyed by tenant slug, `public.role_has_permissions`
 *      carries 169 rows, and every tenant schema has 0 assignments and 0 role→
 *      permission links. Under (A) the `setPermissionsTeamId()` call below is the
 *      entire scoping mechanism — remove it, or unpin the models, and every tenant
 *      user silently resolves against an empty tenant schema and loses every
 *      permission. `teams => true` is load-bearing there, not redundant.
 *
 *  (B) PER-SCHEMA TABLES. The host binds unpinned models (stock spatie, or a
 *      subclass with no `$connection`) and leaves `permission.teams => false`. Each
 *      tenant schema carries its own `roles`/`permissions` set and the central schema
 *      may carry none at all. Measured at ~/Herd/standwell 2026-08-30: no
 *      `public.roles` exists, and each of the 3 tenant schemas holds its own roles and
 *      assignments. Under (B) `setPermissionsTeamId()` is inert — harmless, because
 *      nothing reads the team id while `teams` is false — and the ambient connection
 *      does the scoping.
 *
 * Two things must happen on every tenant switch:
 *  1. Set the teams `team_id` to the tenant key. Load-bearing under (A); inert
 *     under (B). Setting it unconditionally is what lets one bootstrapper serve both.
 *  2. Flush the permission cache. The {@see PermissionRegistrar} is a singleton
 *     resolved in the central context, and its `$cacheKey` is a single constant from
 *     `config('permission.cache.key')` — it encodes neither the team id nor the
 *     schema. So it holds ONE cache repository + one in-memory permission collection
 *     under that key, and the collection cached while one tenant is active leaks into
 *     the next tenant's request. That is a bug under BOTH topologies, for different
 *     reasons: under (A) the collection was resolved under a different `team_id`;
 *     under (B) the cached UUIDs belong to another schema and do not exist in this
 *     one. Either way it surfaces as spurious "this action is unauthorized" denials,
 *     and flushing forces each tenant context to reload its own permissions.
 */
class PermissionsTenancyBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        setPermissionsTeamId($tenant->getTenantKey());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function revert(): void
    {
        setPermissionsTeamId(null);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
