<?php

namespace Splicewire\Beam\Tenancy\Pools;

use Illuminate\Support\Facades\Config;
use RuntimeException;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;

/**
 * Which database server each pool lives on (pooled-storage ticket 13).
 *
 * A pool is a schema, `pool_<name>`; it no longer has to be on the central cluster. A host that needs
 * to spread pooled tenants across servers names a Laravel connection per pool:
 *
 *     'pools' => [
 *         'eu1' => ['connection' => 'pool_eu1'],                       // pool_eu1 on another server
 *         'eu2' => ['connection' => 'pool_eu2', 'rls_user' => [...]],  // with its own non-owner role
 *     ],
 *
 * A pool not listed here, or listed without a `connection`, lives on the central connection — the
 * behaviour every pool had before this class. The named connection is the OWNER template for that
 * server (it runs pool migrations and grants); pooled tenants on it still authenticate as the
 * non-owner role — the pool's own `rls_user`, else the global `beam.tenancy.pooled.rls_user`.
 *
 * The tenant stores only the connection NAME, as stancl's own `tenancy_db_connection` internal (the
 * template connection its `DatabaseConfig` builds a tenant connection from). Credentials stay in host
 * config, never on the tenant row.
 *
 * ⚠️ A pool on another server has no central `public` schema behind it: an unpinned read of a central
 * table from inside such a tenant's frame finds nothing — the same hazard an Isolated Database has
 * (see {@see TenancyConnections}).
 */
class PoolRegistry
{
    /**
     * The owner connection name for this pool's server.
     */
    public function connectionFor(string $pool): string
    {
        return $this->remoteConnectionFor($pool) ?? $this->centralConnectionName();
    }

    /**
     * The connection name a tenant in this pool stores as `tenancy_db_connection`, or null when the
     * pool lives on the central connection (stancl then falls back to its central template).
     */
    public function remoteConnectionFor(string $pool): ?string
    {
        $configured = Config::get("beam.tenancy.pooled.pools.{$pool}.connection");

        if (! is_string($configured) || $configured === '' || $configured === $this->centralConnectionName()) {
            return null;
        }

        $connection = Config::get("database.connections.{$configured}");

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException("Pool '{$pool}' names connection '{$configured}', which is not a defined pgsql connection in database.connections.");
        }

        return $configured;
    }

    /**
     * The non-owner role pooled tenants in this pool connect as: the pool's own, else the global one.
     *
     * @return array{username: ?string, password: string}
     */
    public function rlsUserFor(string $pool): array
    {
        $own = Config::get("beam.tenancy.pooled.pools.{$pool}.rls_user");
        $user = is_array($own) && ! empty($own['username']) ? $own : (array) Config::get('beam.tenancy.pooled.rls_user', []);

        return [
            'username' => isset($user['username']) && $user['username'] !== '' ? (string) $user['username'] : null,
            'password' => (string) ($user['password'] ?? ''),
        ];
    }

    /**
     * Every owner connection pools can live on, each with the non-owner roles its pools use — for
     * provisioning roles server by server.
     *
     * @param  list<string>  $extraPools  pools known from tenant markers but absent from config
     * @return array<string, list<array{username: string, password: string}>>
     */
    public function serversWithRoles(array $extraPools = []): array
    {
        $pools = array_values(array_unique([
            (string) Config::get('beam.tenancy.pooled.default_pool', 'default'),
            ...array_keys((array) Config::get('beam.tenancy.pooled.pools', [])),
            ...$extraPools,
        ]));

        $servers = [];

        foreach ($pools as $pool) {
            $role = $this->rlsUserFor($pool);

            if ($role['username'] === null) {
                continue;
            }

            $server = $this->connectionFor($pool);
            $existing = $servers[$server][$role['username']] ?? null;

            // One role, one password per server: two pools naming the same role with different passwords
            // would leave one of them unable to connect (review finding).
            if ($existing !== null && $existing['password'] !== $role['password']) {
                throw new RuntimeException("Role '{$role['username']}' is configured with two different passwords for pools on connection '{$server}'.");
            }

            $servers[$server][$role['username']] = ['username' => $role['username'], 'password' => $role['password']];
        }

        return array_map('array_values', $servers);
    }

    /**
     * The tables in a pool schema that row-level security actually scopes: RLS enabled AND a policy
     * present (any name — a host may write its own). Only these hold per-tenant rows. A table the preparer skipped (listed in
     * `postgres-rls.exclude`, or with an incompatible discriminator) is pool-level data with no policy,
     * and a DELETE or copy through a tenant frame would touch every tenant's rows in it (review finding).
     *
     * @return list<string>
     */
    public static function policyTables(\Illuminate\Database\Connection $connection, string $schema): array
    {
        return array_map(fn ($row) => $row->table_name, $connection->select(<<<'SQL'
            SELECT c.relname AS table_name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ?
              AND c.relkind IN ('r', 'p')
              AND c.relrowsecurity
              AND EXISTS (
                  SELECT 1 FROM pg_policies p
                  WHERE p.schemaname = n.nspname AND p.tablename = c.relname
              )
            ORDER BY c.relname
        SQL, [$schema]));
    }

    /**
     * The pool a `pool_<name>` schema belongs to, or null for any other name.
     */
    public static function poolFromSchema(string $schema): ?string
    {
        $prefix = (string) Config::get('beam.tenancy.pooled.schema_prefix', 'pool_');

        return $prefix !== '' && str_starts_with($schema, $prefix) ? substr($schema, strlen($prefix)) : null;
    }

    protected function centralConnectionName(): string
    {
        return TenancyConnections::central() ?? (string) Config::get('database.default');
    }
}
