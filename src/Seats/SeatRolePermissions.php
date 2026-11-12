<?php

namespace Splicewire\Beam\Tenancy\Seats;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permissions a seat's tier role holds by default (analytics-area 16, owner ruling 2026-11-10: a PACKAGE default):
 * `beam.tenancy.seat_role_permissions`, role name => permission names. Out of the box the tenant `Admin` role, which
 * {@see WorkspaceSeats} gives a workspace's owner and admin seats (`beam.tenancy.seat_roles`), holds `analytics.view`, so
 * every host's workspace owner and admin open its Analytics section without the host re-deriving the grant. Admin also
 * holds `billing.view` (launch ticket 41, owner ruling 2026-11-12), so the same seats open billing; tenant realm only.
 *
 * The role itself stays the host's: it is created by the host's permission seeder (the flagship's `PermissionsSeeder`, the
 * tower starter's through Tower's `SeedPermissions` step), never here. So the grant is applied where tenancy meets the
 * role: whenever it SEATS someone into it ({@see WorkspaceSeats}, {@see \Splicewire\Beam\Tenancy\Tenant::assignOwner()}),
 * which is the first moment a fresh tenant's role is known to exist, and by the shared back-fill migration for roles that
 * already hold seats.
 *
 * ADD-ONLY and idempotent: it creates a missing permission row and a missing grant, and never revokes or re-syncs anything
 * else the role holds. It writes on the AMBIENT connection, the one tenancy has already switched to, and does nothing when
 * that connection carries no role tables or no such role. An empty map opts a host out.
 */
class SeatRolePermissions
{
    /** The package default, used when the host's config names none. */
    public const DEFAULTS = ['Admin' => ['analytics.view', 'billing.view']];

    /** @return array<string, list<string>> */
    public static function map(): array
    {
        $map = config('beam.tenancy.seat_role_permissions', self::DEFAULTS);

        return is_array($map) ? array_map(fn ($names): array => array_values(array_filter((array) $names, 'is_string')), $map) : [];
    }

    /** Give the role `$roleName` (every guard it exists on) the permissions the map names for it. Returns the grants added. */
    public function ensure(string $roleName): int
    {
        $names = self::map()[$roleName] ?? [];
        $roleModel = config('permission.models.role');
        $permissionModel = config('permission.models.permission');

        if ($names === [] || $roleModel === null || $permissionModel === null
            || ! Schema::hasTable((new $roleModel)->getTable()) || ! Schema::hasTable((new $permissionModel)->getTable())) {
            return 0;
        }

        $registrar = app(PermissionRegistrar::class);
        $teamKey = config('permission.teams') ? config('permission.column_names.team_foreign_key', 'team_id') : null;
        $previousTeam = $teamKey !== null ? $registrar->getPermissionsTeamId() : null;
        $added = 0;

        try {
            foreach ($roleModel::query()->where('name', $roleName)->get() as $role) {
                if ($teamKey !== null) {
                    $registrar->setPermissionsTeamId($role->{$teamKey});
                }
                $guard = $role->guard_name ?? Guard::getDefaultName($permissionModel);

                foreach ($names as $name) {
                    $permission = $permissionModel::findOrCreate($name, $guard);
                    if (! $role->permissions()->whereKey($permission->getKey())->exists()) {
                        $role->givePermissionTo($permission);
                        $added++;
                    }
                }
            }
        } finally {
            if ($teamKey !== null) {
                $registrar->setPermissionsTeamId($previousTeam);
            }
            $registrar->forgetCachedPermissions();
        }

        return $added;
    }
}
