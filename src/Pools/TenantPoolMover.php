<?php

namespace Splicewire\Beam\Tenancy\Pools;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Splicewire\Beam\Tenancy\HybridPostgresTenantDatabaseManager;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;
use Splicewire\Beam\Tenancy\Tenant;
use Throwable;

/**
 * Move a pooled tenant into another pool — on the same server or another one (pooled-storage
 * ticket 13). An option this package offers; nothing calls it but `pools:move`.
 *
 * ## Order, and where authority sits
 *
 * 1. Refuse: not pooled, same pool, a direct-access role bound to the old pool.
 * 2. Ensure the target pool (created, migrated, prepared, granted — {@see PoolMigrator}).
 * 3. Block writes (`write_blocked_at`) for the copy.
 * 4. In ONE transaction on the target server, as the target pool's non-owner role with the tenant's own
 *    key bound: delete any rows this tenant already has there (a partial earlier attempt — policy-scoped,
 *    so only this tenant's), then insert every row read from the source frame, minus the discriminator
 *    (the target's column default stamps the key). Per-table row counts must match before commit.
 * 5. One write flips the tenant: `pool`, `db_name`, `db_connection`. The session setting is unchanged —
 *    same key, same setting name.
 * 6. Delete the tenant's rows from the source pool, on a frame re-verified as the source's scoped frame.
 *
 * Before 5, the source pool is authoritative and any failure leaves the tenant exactly where it was
 * (writes unblocked). After 5, the target is authoritative; a failure in 6 leaves the old rows in the
 * source pool for manual cleanup, and the tenant is marked failed saying so. The two servers never share
 * a transaction, which is why the copy commits before the flip and the source is cleared after it.
 *
 * ## Foreign keys in a shared pool
 *
 * The target pool is live for its other tenants, so its foreign keys cannot be dropped around the copy
 * (contrast tower's promotion, which owns its target schema). Instead each table is inserted inside its
 * own SAVEPOINT and retried in a later pass when a foreign key refuses it; tables that still refuse once
 * no table makes progress fall back to row-by-row passes, which resolves self-references and parent rows
 * in later chunks. A cycle no row order can satisfy fails before the flip.
 *
 * ## What it does not cover
 *
 * - The write block is only what the host enforces for `write_blocked_at` (the flagship's
 *   BlockTenantWrites covers unsafe tenant-API requests, not queued jobs or console work) — move a quiet
 *   tenant. A save of a tenant model loaded before the flip rewrites `data` whole and would restore the
 *   old markers.
 * - Rows carrying ids that another tenant in the target pool already holds collide on the primary key
 *   until pool keys are tenant-scoped (ticket 11); that fails before the flip.
 * - A pool on another server has no central `public` schema behind it (see {@see PoolRegistry}).
 * - Only tables the row-level-security policy scopes are moved ({@see PoolRegistry::policyTables()});
 *   excluded, pool-level tables stay where they are.
 * - Ids are copied as they are. A `serial`/`bigserial` key is not advanced on the target (the non-owner
 *   role cannot `setval`), so such a table would collide on its next insert; the shared set uses uuids.
 */
class TenantPoolMover
{
    public function __construct(
        protected PoolRegistry $registry,
        protected PoolMigrator $migrator,
    ) {}

    /**
     * @return array<string, int> rows moved per table
     */
    public function move(Tenant $tenant, string $targetPool): array
    {
        if (! $tenant->isPooled()) {
            throw new RuntimeException("Tenant '{$tenant->getTenantKey()}' is not on Pooled storage — only a pooled tenant can move between pools.");
        }

        $sourcePool = (string) $tenant->pool;
        $targetSchema = PoolMigrator::schemaNameFor($targetPool);

        if ($sourcePool === $targetPool) {
            throw new RuntimeException("Tenant '{$tenant->getTenantKey()}' is already in pool '{$targetPool}'.");
        }

        if ($tenant->hasDirectAccessRole()) {
            throw new RuntimeException("Tenant '{$tenant->getTenantKey()}' has a direct-access role ('{$tenant->direct_access_role}') bound to pool '{$sourcePool}'. Revoke it first: php artisan splicewire:beam:tenancy:pools:direct-access {$tenant->getTenantKey()} --revoke");
        }

        $key = (string) $tenant->getTenantKey();
        $sourceSchema = (string) $tenant->poolSchema();
        $sourceName = 'beam_pool_move_source_'.$key;
        $targetName = 'beam_pool_move_target_'.$key;

        // Captured before anything changes: after the flip the tenant's own config points at the target.
        Config::set("database.connections.{$sourceName}", $tenant->database()->connection());
        DB::purge($sourceName);

        try {
            $tenant->markProvisioning('pool_move_prepare_target', "Preparing pool '{$targetPool}' ({$targetSchema})");
            $this->migrator->ensure($targetPool);
            $this->registerScopedConnection($targetName, $targetPool, $tenant);

            $source = DB::connection($sourceName);
            $target = DB::connection($targetName);
            HybridPostgresTenantDatabaseManager::assertScopedFrame($source, $tenant);
            $this->assertTargetFrame($target, $targetPool, $tenant);

            $tables = PoolRegistry::policyTables($source, $sourceSchema);
            $this->assertTargetHasTables($target, $targetSchema, $tables);

            $tenant->write_blocked_at = now()->toDateTimeString();
            $tenant->save();

            $tenant->markProvisioning('pool_move_copy', "Copying rows from pool '{$sourcePool}' to pool '{$targetPool}'");
            $moved = $target->transaction(function () use ($source, $target, $tables) {
                $this->deleteInPasses($target, $tables);

                return $this->copy($source, $target, $tables);
            });

            $tenant->markProvisioning('pool_move_flip', "Cutting over to pool '{$targetPool}'");
            $sourceConnection = $tenant->tenancy_db_connection;
            $tenant->markPooled($targetPool);
            $tenant->setInternal('db_name', $targetSchema);
            $tenant->setInternal('db_connection', $this->registry->remoteConnectionFor($targetPool));
            $tenant->write_blocked_at = null;

            try {
                $tenant->save();
            } catch (Throwable $saveFailed) {
                // The flip did not persist: put the in-memory markers back, so the failure below records
                // the tenant where it really is instead of saving a half-applied cutover (review finding).
                $tenant->markPooled($sourcePool);
                $tenant->setInternal('db_name', $sourceSchema);
                $tenant->setInternal('db_connection', $sourceConnection);
                $tenant->write_blocked_at = now()->toDateTimeString();

                throw $saveFailed;
            }
        } catch (Throwable $e) {
            if ($tenant->pool === $sourcePool && $tenant->write_blocked_at !== null) {
                $tenant->write_blocked_at = null;
                $tenant->save();
            }

            $tenant->markFailed("Moving to pool '{$targetPool}' failed BEFORE cutover — pool '{$sourcePool}' is still authoritative: {$e->getMessage()}");
            TenancyConnections::forget($sourceName);
            TenancyConnections::forget($targetName);

            throw $e;
        }

        TenancyConnections::forget($targetName);

        try {
            $tenant->markProvisioning('pool_move_cleanup', "Deleting the tenant's rows from pool '{$sourcePool}'");
            $source = DB::connection($sourceName);
            HybridPostgresTenantDatabaseManager::assertScopedFrame($source, (clone $tenant)->markPooled($sourcePool));
            $this->cleanupSource($source, $tables, $moved);
        } catch (Throwable $e) {
            $tenant->markFailed("Moved to pool '{$targetPool}' — it is authoritative — but deleting the old rows from pool '{$sourcePool}' ({$sourceSchema}) failed; they need manual deletion: {$e->getMessage()}");

            throw $e;
        } finally {
            TenancyConnections::forget($sourceName);
        }

        $tenant->markActive();

        return $moved;
    }

    /**
     * Delete the tenant's old rows — but only if the source still holds exactly what was copied. The write
     * block does not stop queued or console work already in the tenant's frame; a row written to the old
     * pool after the copy was never moved, and deleting it would lose it. A mismatch keeps every old row
     * and fails, so the difference can be reconciled (review finding).
     *
     * @param  list<string>  $tables
     * @param  array<string, int>  $moved
     */
    protected function cleanupSource(Connection $source, array $tables, array $moved): void
    {
        $source->transaction(function () use ($source, $tables, $moved) {
            foreach ($tables as $table) {
                $now = $source->table($table)->count();

                if ($now !== ($moved[$table] ?? 0)) {
                    throw new RuntimeException("'{$table}' in the old pool now holds {$now} row(s) for this tenant, but {$moved[$table]} were copied — rows were written during the move. Old rows kept for reconciliation.");
                }
            }

            $this->deleteInPasses($source, $tables);
        });
    }

    /** The target pool's server, as its non-owner role, with this tenant's key bound. */
    protected function registerScopedConnection(string $name, string $pool, Tenant $tenant): void
    {
        $role = $this->registry->rlsUserFor($pool);

        if ($role['username'] === null) {
            throw new RuntimeException("Pool '{$pool}' has no non-owner RLS role configured.");
        }

        $config = (array) Config::get('database.connections.'.$this->registry->connectionFor($pool));
        $config['search_path'] = PoolMigrator::schemaNameFor($pool).',public';
        $config['username'] = $role['username'];
        $config['password'] = $role['password'];
        $config['session_settings'] = [
            (string) Config::get('beam.tenancy.pooled.session_setting', 'app.tenant_id') => (string) $tenant->getTenantKey(),
        ];

        Config::set("database.connections.{$name}", $config);
        DB::purge($name);
    }

    protected function assertTargetFrame(Connection $target, string $pool, Tenant $tenant): void
    {
        $setting = (string) Config::get('beam.tenancy.pooled.session_setting', 'app.tenant_id');
        $role = $this->registry->rlsUserFor($pool)['username'];
        $row = $target->selectOne('select current_user as u, current_setting(?, true) as k', [$setting]);

        if ($row->u !== $role || $row->k !== (string) $tenant->getTenantKey()) {
            throw new RuntimeException("Refusing to write into pool '{$pool}': the connection is '{$row->u}' with {$setting} = '".($row->k ?? 'unset')."', not '{$role}' bound to this tenant.");
        }
    }

    /** @param  list<string>  $tables */
    protected function assertTargetHasTables(Connection $target, string $schema, array $tables): void
    {
        $missing = array_values(array_diff($tables, PoolRegistry::policyTables($target, $schema)));

        if ($missing !== []) {
            throw new RuntimeException("Pool schema '{$schema}' lacks tables the source pool has: ".implode(', ', $missing).' — migrate both pools from the same migration set.');
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    protected function copy(Connection $source, Connection $target, array $tables): array
    {
        $discriminator = (string) Config::get('postgres-rls.column', 'tenant_id');
        $rows = [];

        foreach ($tables as $table) {
            $rows[$table] = $source->table($table)->get()->map(function ($row) use ($discriminator) {
                $attributes = (array) $row;
                unset($attributes[$discriminator]);

                return $attributes;
            })->all();
        }

        // Table passes, each table in its own savepoint.
        $pending = array_keys(array_filter($rows));
        $progress = true;
        while ($pending !== [] && $progress) {
            $progress = false;
            foreach ($pending as $i => $table) {
                if ($this->tryInsert($target, $table, $rows[$table])) {
                    unset($pending[$i]);
                    $progress = true;
                }
            }
        }

        // Row passes for whatever a foreign key still refuses as a whole table.
        $pendingRows = [];
        foreach ($pending as $table) {
            foreach ($rows[$table] as $row) {
                $pendingRows[] = [$table, $row];
            }
        }
        $progress = true;
        while ($pendingRows !== [] && $progress) {
            $progress = false;
            foreach ($pendingRows as $i => [$table, $row]) {
                if ($this->tryInsert($target, $table, [$row])) {
                    unset($pendingRows[$i]);
                    $progress = true;
                }
            }
        }

        if ($pendingRows !== []) {
            $tablesLeft = array_values(array_unique(array_map(fn ($entry) => $entry[0], $pendingRows)));

            throw new RuntimeException(count($pendingRows).' row(s) could not be inserted in any order (foreign keys): '.implode(', ', $tablesLeft));
        }

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = count($rows[$table]);
            $landed = $target->table($table)->count();

            if ($landed !== $counts[$table]) {
                throw new RuntimeException("Row count mismatch moving '{$table}': source had {$counts[$table]}, target has {$landed}.");
            }
        }

        return $counts;
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function tryInsert(Connection $target, string $table, array $rows): bool
    {
        try {
            $target->transaction(function () use ($target, $table, $rows) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    $target->table($table)->insert($chunk);
                }
            });

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23503') { // only a foreign-key refusal is retried
                throw $e;
            }

            return false;
        }
    }

    /**
     * Delete this tenant's rows (the frame is policy-scoped) table by table, each in a savepoint, retrying
     * tables a foreign key refuses until none is left.
     *
     * @param  list<string>  $tables
     */
    protected function deleteInPasses(Connection $connection, array $tables): void
    {
        $remaining = $tables;
        $passes = 0;

        while ($remaining !== [] && $passes < count($tables) + 1) {
            $refused = [];
            foreach ($remaining as $table) {
                try {
                    $connection->transaction(fn () => $connection->table($table)->delete());
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) !== '23503') {
                        throw $e;
                    }
                    $refused[] = $table;
                }
            }
            $remaining = $refused;
            $passes++;
        }

        if ($remaining !== []) {
            throw new RuntimeException('Rows could not be deleted from: '.implode(', ', $remaining));
        }
    }
}
