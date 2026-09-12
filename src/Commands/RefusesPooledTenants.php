<?php

namespace Splicewire\Beam\Tenancy\Commands;

/**
 * For stancl's destructive per-tenant commands (pooled-storage ticket 05): a pooled tenant shares its
 * schema with every other tenant of the pool, so `tenants:migrate-fresh` (a `db:wipe` of the tenant
 * connection — the WHOLE pool) and `tenants:rollback` (rolling the pool's ledger back for everyone)
 * are pool-level decisions, not per-tenant ones. Refuse, name the pool, exit non-zero. Migrating a pool
 * forward is `splicewire:beam:tenancy:pools:migrate`; rolling one back is a deliberate operator act
 * this package does not automate.
 *
 * `tenants:migrate` is the one that filters rather than refuses ({@see PooledAwareTenantsMigrate}),
 * because skipping a pooled tenant there is exactly correct — the pool is migrated once elsewhere.
 */
trait RefusesPooledTenants
{
    /** @return list<string> the pooled tenant keys among the targeted tenants, with their pool schema */
    protected function pooledTargets(): array
    {
        return $this->getTenants()->collect()
            ->filter(fn ($tenant) => method_exists($tenant, 'isPooled') && $tenant->isPooled())
            ->map(fn ($tenant) => "{$tenant->getTenantKey()} ({$tenant->poolSchema()})")
            ->values()
            ->all();
    }

    protected function refuseIfPooled(string $verb): bool
    {
        $pooled = $this->pooledTargets();

        if ($pooled === []) {
            return false;
        }

        $this->error("Refusing to {$verb} pooled tenant(s) — the schema is shared with every tenant of the pool: ".implode(', ', $pooled).'. Migrate the pool with splicewire:beam:tenancy:pools:migrate; a rollback or wipe of a pool is an operator decision.');

        return true;
    }
}
