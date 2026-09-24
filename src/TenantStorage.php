<?php

namespace Splicewire\Beam\Tenancy;

/**
 * The three storage states a Tenant's Postgres data can be in — DERIVED from the markers the tenant
 * carries, never stored as a fourth marker of its own (pooled-storage ticket 04).
 *
 * Isolated wins over Pooled, and Pooled over Schema, because that is the order the markers are written
 * in a tenant's life: a tenant is provisioned pooled or schema, and only later cut over to an Isolated
 * Database by a live migration that never clears the earlier markers in the same write.
 *
 * ⚠️ Vocabulary: this is a STORAGE state. "Isolation level" is the satellite Org ladder (app ADR-0057)
 * and never names a tenant's storage; "tier" is billing vocabulary (Plan/Entitlement). See the flagship
 * `CONTEXT.md` glossary.
 */
enum TenantStorage: string
{
    /** Many tenants in one shared schema, rows kept apart by Postgres row-level security. */
    case Pooled = 'pooled';

    /** One schema per tenant on the shared cluster — the default since the platform began. */
    case Schema = 'schema';

    /** A dedicated database server — "Isolated Database" (tenant-database-upsell). */
    case Isolated = 'isolated';

    /**
     * The two states a tenant may be CREATED into; Isolated only ever arrives by migration. The single
     * source for that rule — `DecideStorage` derives from it, and `CreateTenantData::$storage`'s `#[In]`
     * (a constant expression, so it lists the cases) is pinned equal to it by a test.
     *
     * @return list<self>
     */
    public static function creatable(): array
    {
        return [self::Pooled, self::Schema];
    }

    /** @return list<string> */
    public static function creatableValues(): array
    {
        return array_map(fn (self $storage) => $storage->value, self::creatable());
    }
}
