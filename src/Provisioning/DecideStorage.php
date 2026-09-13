<?php

namespace Splicewire\Beam\Tenancy\Provisioning;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Splicewire\Beam\Tenancy\Contracts\ResolvesTenantStorage;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantStorage;

/**
 * Decide a NEW tenant's storage state before anything touches a database (pooled-storage ticket 04).
 *
 * A stancl-shaped pipeline step — same constructor and `handle()` as `Stancl\Tenancy\Jobs\CreateDatabase`
 * — so the tier that owns the provisioning pipeline can register it in
 * {@see TenantProvisioningStepRegistry} AHEAD of `CreateDatabase`. This package ships no pipeline and
 * does not register the step itself; ordering is the pipeline owner's declaration (ticket 07/08).
 *
 * Precedence, first answer wins:
 *   1. the tenant's own `requested_storage` (what the operator or broker asked for at creation),
 *   2. the host's {@see ResolvesTenantStorage} binding, if any,
 *   3. `beam.tenancy.pooled.default_for_new_tenants` (false → Schema, the historical default).
 *
 * The step is a no-op on a tenant that already carries a storage marker, so re-running a pipeline
 * (`TenantProvisioning::retry()`) cannot flip a tenant's storage under it. It writes exactly one
 * marker — `pool` — and only for the Pooled outcome; Schema is the absence of markers, and Isolated is
 * never a creation-time outcome (customer-supplied isolation arrives only by the live-migration job,
 * see `HybridPostgresTenantDatabaseManager::createDatabase()`), so both `requested_storage = isolated`
 * and a resolver answering Isolated throw rather than being quietly coerced.
 */
class DecideStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Tenant $tenant) {}

    public function handle(): void
    {
        if ($this->tenant->isIsolatedDatabase() || $this->tenant->isPooled()) {
            return;
        }

        // A tenant that already has storage is never re-decided. Schema is the ABSENCE of markers,
        // so the marker checks above cannot see an existing schema tenant — but stancl's
        // CreateDatabase writes `db_name` on every tenant it provisions, so its presence means
        // "storage already exists". Without this, `TenantProvisioning::retry()` on an existing
        // schema tenant, under a pooled default, would mark it pooled while its data stays in
        // `tenant_<id>`. (Measured on the local dev database 2026-09-12: 18 of 18 tenants carry it.)
        if ($this->tenant->getInternal('db_name') !== null) {
            return;
        }

        $storage = $this->requested() ?? $this->resolved() ?? $this->default();

        if (! in_array($storage, TenantStorage::creatable(), true)) {
            throw new InvalidArgumentException(
                "Tenant '{$this->tenant->getTenantKey()}' cannot be created as {$storage->value} storage (Isolated Database arrives only by migration)."
            );
        }

        if ($storage === TenantStorage::Pooled) {
            $this->tenant->markPooled((string) config('beam.tenancy.pooled.default_pool', 'default'));
            $this->tenant->save();
        }
    }

    protected function requested(): ?TenantStorage
    {
        $requested = $this->tenant->requested_storage;

        if ($requested === null || $requested === '') {
            return null;
        }

        return TenantStorage::tryFrom((string) $requested)
            ?? throw new InvalidArgumentException("Unknown requested_storage '{$requested}' on tenant '{$this->tenant->getTenantKey()}'.");
    }

    protected function resolved(): ?TenantStorage
    {
        $class = config('beam.tenancy.pooled.storage_resolver');

        if (! is_string($class) || $class === '') {
            return null;
        }

        $resolver = app($class);

        if (! $resolver instanceof ResolvesTenantStorage) {
            throw new InvalidArgumentException("beam.tenancy.pooled.storage_resolver names {$class}, which does not implement ".ResolvesTenantStorage::class.'.');
        }

        return $resolver->resolve($this->tenant);
    }

    /**
     * The host default. A pooled default on a host with no non-owner RLS role configured falls back
     * to Schema with a warning, rather than failing every new tenant: the default is a preference,
     * and a host that has not provisioned the role (`pools:role` + `BEAM_TENANCY_RLS_*`) cannot honour
     * it. An EXPLICIT pooled request or resolver answer still fails loud on such a host.
     */
    protected function default(): TenantStorage
    {
        if (! config('beam.tenancy.pooled.default_for_new_tenants', false)) {
            return TenantStorage::Schema;
        }

        if (empty(config('beam.tenancy.pooled.rls_user.username'))) {
            Log::warning("beam-tenancy: pooled is the default for new tenants but no RLS role is configured (BEAM_TENANCY_RLS_USERNAME); tenant '{$this->tenant->getTenantKey()}' gets Schema storage.");

            return TenantStorage::Schema;
        }

        return TenantStorage::Pooled;
    }
}
