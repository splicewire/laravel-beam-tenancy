<?php

namespace Splicewire\Beam\Tenancy\Entitlements;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Tenancy\Seats\SeatRolePermissions;
use Throwable;

/**
 * Inside a tenant, a principal holding the `analytics.view` PERMISSION holds the `analytics.view` ENTITLEMENT
 * (analytics-area 16, owner ruling 2026-11-10: a package default; charter-accepted design).
 *
 * The Analytics section's gate asks `entitlement:analytics.view`, which beam defines over the bound
 * {@see EntitlementResolver}. beam-accounts composes that key from realm grants it reads through `$principal->teams`, and a
 * tenant's seated user (a TenantUser) carries no such team, so a workspace owner or admin composed nothing on a host that
 * did not write its own Gate line (measured on the sole tower starter, ticket 06). Hosts bind different resolvers (the
 * tower starter its own `TowerEntitlementResolver`), so this DECORATES whichever one is bound rather than adding a
 * beam-accounts grant source the tower resolver never asks.
 *
 * NARROW by contract (coord-lead 12:11Z): it adds ONLY `analytics.view`, ONLY while a tenant is initialized, and ONLY when
 * the principal itself holds the permission (the tenant `Admin` role's default, {@see SeatRolePermissions}). Every other
 * key, and every answer outside a tenant, is the inner resolver's unchanged. A host that defines its own
 * `entitlement:analytics.view` Gate (the flagship) still decides alone: beam never redefines a host's gate.
 */
final class TenantAnalyticsEntitlement implements EntitlementResolver
{
    public const KEY = 'analytics.view';

    public function __construct(private readonly EntitlementResolver $inner) {}

    public function entitlementsFor(mixed $principal): array
    {
        $keys = $this->inner->entitlementsFor($principal);

        if (in_array(self::KEY, $keys, true) || ! $this->holdsPermissionInTenant($principal)) {
            return $keys;
        }

        return [...$keys, self::KEY];
    }

    private function holdsPermissionInTenant(mixed $principal): bool
    {
        if (! is_object($principal) || ! method_exists($principal, 'hasPermissionTo') || ! function_exists('tenancy')) {
            return false;
        }

        try {
            if (! tenancy()->initialized) {
                return false;
            }

            // A permission row the tenant does not carry makes spatie throw; that is "does not hold it", never an error.
            return (bool) $principal->hasPermissionTo(self::KEY);
        } catch (Throwable) {
            return false;
        }
    }
}
