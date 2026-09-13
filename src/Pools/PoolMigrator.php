<?php

namespace Splicewire\Beam\Tenancy\Pools;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Rushing\PostgresRls\RowLevelSecurity\PreparationReport;
use Rushing\PostgresRls\RowLevelSecurity\RoleGrants;
use Rushing\PostgresRls\RowLevelSecurity\SchemaPreparer;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;

/**
 * A pool is migrated ONCE, as the owner (pooled-storage ticket 05). This is the one path that creates
 * and migrates a pool schema — the hybrid manager's pooled `createDatabase()` calls it for the first
 * tenant of a pool, and `splicewire:beam:tenancy:pools:migrate` calls it for every later migration —
 * so a pool can never exist un-prepared or be prepared two different ways.
 *
 * The sequence, on a transient owner connection whose `search_path` is `"<pool schema>,public"`
 * (the same `,public` fall-through every tenant schema relies on, see `TenancyConnections`) and
 * whose session setting is bound to the pool's own key ({@see poolKey()}):
 *
 *   1. `CREATE SCHEMA IF NOT EXISTS` — the owner is the central connection's user.
 *   2. Drop the row-level-security policies. A policy that references a column blocks some ALTERs
 *      on it; while the policies are absent RLS stays ENABLED, so a non-owner connection reads zero
 *      rows rather than everything (measured, ticket 01 check 18).
 *   3. Run the migrations `tenants:migrate` would have run — the paths under
 *      `tenancy.migration_parameters.--path` (the shared set this package pushes there, plus the
 *      host's tenant path) — with `--database` pointed at the transient connection, so the pool
 *      keeps its own `migrations` ledger inside its schema.
 *   4. Re-prepare: discriminator column, index, ENABLE (and FORCE when configured), the hashed policy
 *      (outdated ones recreated, zombies dropped), unique indexes rewritten. `rushing/laravel-postgres-rls`.
 *   5. Re-grant the non-owner role, including default privileges for tables the migration just added.
 *   6. Purge the transient connection.
 *
 * Never inside a tenant frame: a data backfill inside one tenant's frame would be scoped to that
 * tenant's rows by the policy and leave every other tenant un-backfilled behind a green exit.
 */
class PoolMigrator
{
    /**
     * The key the pool's OWNER connection binds while migrating (`pool:<name>` — a colon, which no
     * tenant key can carry). Measured at the flagship (pooled-storage 07): a post-migrate listener
     * seeds `roles` and `permissions` after `migrate` returns, so a pool migrated with no key bound
     * held rows that belonged to nobody and the preparer rightly refused `SET NOT NULL`. With this key
     * bound, every row a migration or listener inserts is stamped to the pool — invisible to every
     * tenant under the policy, never NULL — and per-tenant seed data keeps arriving the way it always
     * has: through the provisioning steps that run INSIDE each tenant's frame (`SeedPermissions`…).
     */
    public static function poolKey(string $pool): string
    {
        return 'pool:'.$pool;
    }

    /**
     * Create the pool if it is absent, then migrate and prepare it. Idempotent.
     */
    public function ensure(string $pool): PreparationReport
    {
        return $this->migrate($pool);
    }

    public function migrate(string $pool): PreparationReport
    {
        $schema = $this->schemaFor($pool);
        $name = $this->registerOwnerConnection($schema, $pool);

        try {
            $connection = DB::connection($name);
            $connection->statement('CREATE SCHEMA IF NOT EXISTS '.$this->quote($schema));

            $preparer = SchemaPreparer::fromConfig($connection, $schema, (array) Config::get('postgres-rls', []), [
                'setting' => Config::get('beam.tenancy.pooled.session_setting'),
                'force' => Config::get('beam.tenancy.pooled.force_rls'),
            ]);

            // The policies come down only when there is something to migrate: a run with nothing
            // pending must be a no-op (the second run of the command, every tenant after the first),
            // and must not open a policy-less window for nothing.
            if ($this->pendingMigrations($name) !== []) {
                $preparer->dropPolicies();

                $exit = Artisan::call('migrate', [
                    '--database' => $name,
                    '--path' => $this->migrationPaths(),
                    '--realpath' => true,
                    '--force' => true,
                ]);

                if ($exit !== 0) {
                    throw new RuntimeException("Migrating pool '{$pool}' ({$schema}) failed: ".Artisan::output());
                }
            }

            $report = $preparer->prepare();

            $role = Config::get('beam.tenancy.pooled.rls_user.username');
            if (is_string($role) && $role !== '') {
                if ($connection->selectOne('select 1 as ok from pg_roles where rolname = ?', [$role]) === null) {
                    throw new RuntimeException("The pooled RLS role '{$role}' does not exist on this cluster — run `php artisan splicewire:beam:tenancy:pools:role` once before pooling tenants.");
                }

                (new RoleGrants($connection, $schema))->grant($role);
            }

            return $report;
        } finally {
            TenancyConnections::forget($name);
        }
    }

    /**
     * Migration names on disk that the pool's own ledger has not recorded — the same comparison
     * `migrate` makes, taken on the pool connection without disturbing the default connection.
     *
     * @return list<string>
     */
    protected function pendingMigrations(string $connection): array
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        $paths = $this->migrationPaths();

        return $migrator->usingConnection($connection, function () use ($migrator, $paths) {
            $files = array_keys($migrator->getMigrationFiles($paths));
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

            return array_values(array_diff($files, $ran));
        });
    }

    public function schemaFor(string $pool): string
    {
        return self::schemaNameFor($pool);
    }

    /**
     * THE pool-name grammar and the one place a pool name becomes a schema name. A pool name is spliced
     * into `search_path` and raw DDL, so every writer validates here (`Tenant::markPooled()`,
     * `Tenant::poolSchema()`, the command) — `docs/agents/traps/tenancy.md`: identifiers that cross
     * SQL search paths are validated at every writer, not by a seeder-only guard.
     */
    public static function schemaNameFor(string $pool): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $pool)) {
            throw new RuntimeException("Pool name '{$pool}' must match [a-z0-9_]+ — it becomes a Postgres schema name.");
        }

        $schema = Config::get('beam.tenancy.pooled.schema_prefix', 'pool_').$pool;

        if (strlen($schema) > 63) {
            throw new RuntimeException("Pool schema name '{$schema}' exceeds Postgres's 63-byte identifier limit.");
        }

        return $schema;
    }

    /**
     * The migration paths `tenants:migrate` would run — stancl reads `tenancy.migration_parameters.--path`
     * and falls back to `database/migrations/tenant`; this mirrors `DealsWithMigrations::getMigrationPaths()`.
     *
     * @return list<string>
     */
    protected function migrationPaths(): array
    {
        $paths = (array) Config::get('tenancy.migration_parameters.--path', []);

        return $paths !== [] ? array_values($paths) : [database_path('migrations/tenant')];
    }

    /**
     * A transient connection on the CENTRAL template (stancl's `central_connection`, else the default),
     * as the owner, with the pool spliced into `search_path`. Named per pool so two pools migrating in
     * one process never share a PDO.
     */
    protected function registerOwnerConnection(string $schema, string $pool): string
    {
        $template = TenancyConnections::central() ?? Config::get('database.default');
        $config = Config::get("database.connections.{$template}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException("Pooled storage needs a pgsql central connection; '{$template}' is not one.");
        }

        $config['search_path'] = "{$schema},public";
        $config['session_settings'] = [
            (string) Config::get('beam.tenancy.pooled.session_setting', 'app.tenant_id') => self::poolKey($pool),
        ];

        $name = "beam_pool_{$schema}";
        Config::set("database.connections.{$name}", $config);
        DB::purge($name);

        return $name;
    }

    protected function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
