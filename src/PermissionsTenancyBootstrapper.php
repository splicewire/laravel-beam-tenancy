<?php

namespace Splicewire\Beam\Tenancy;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Scope spatie/laravel-permission to the active tenant.
 *
 * Two things must happen on every tenant switch:
 *  1. Set the teams `team_id` to the tenant key so role/permission resolution is
 *     tenant-scoped.
 *  2. Flush the permission cache. The {@see PermissionRegistrar} is a singleton
 *     resolved in the central context, so it holds ONE cache repository + one
 *     in-memory permission collection under a single key — not prefixed per tenant.
 *     Without a flush on switch, the collection cached while one tenant is active
 *     leaks into the next tenant's request (the permission tables are per-schema, so
 *     the cached UUIDs don't even exist in the other schema), which surfaces as
 *     spurious "this action is unauthorized" denials. Flushing forces each tenant
 *     context to reload its own schema's permissions.
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
