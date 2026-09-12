<?php

namespace Splicewire\Beam\Tenancy\Contracts;

use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantStorage;

/**
 * The seam through which a tier that knows about plans decides a NEW tenant's storage (pooled-storage
 * ticket 04). Bound by class-string at `beam.tenancy.pooled.storage_resolver`; null by default.
 *
 * beam-commerce REQUIRES beam-tenancy, so this package can never name a Plan — the same cycle that
 * `billing_account_model` exists to avoid. The implementation lives upward (tower binds one that reads
 * the tenant's plan); this package only asks the question.
 *
 * Return null to decline: the caller falls through to `beam.tenancy.pooled.default_for_new_tenants`.
 * Returning `TenantStorage::Isolated` is a contract violation — creation never yields an Isolated
 * Database — and {@see \Splicewire\Beam\Tenancy\Provisioning\DecideStorage} rejects it loudly.
 */
interface ResolvesTenantStorage
{
    public function resolve(Tenant $tenant): ?TenantStorage;
}
