<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Tenancy\Pools\PoolRegistry;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * Provision the NON-OWNER Postgres role pooled tenant connections authenticate as (pooled-storage
 * ticket 07). Once per cluster, by an operator or a stamp's setup — never by a request.
 *
 * Reads each pool's role (its own `rls_user`, else `beam.tenancy.pooled.rls_user`) and, on each pool's
 * server — the central connection unless a pool names another (ticket 13) — whose
 * user needs `CREATEROLE` — Herd's and Cloud SQL's default users both hold it), creates the role with
 * `LOGIN` and that password, or resets the password when the role already exists. Nothing else: no
 * `SUPERUSER`, no `BYPASSRLS`, no ownership — the whole point of the role is that the policy binds it.
 * Grants on each pool schema are `pools:migrate`'s job, re-run after every migration.
 *
 * Idempotent, and it says which of the two it did.
 */
class PoolsRole extends Command
{
    protected $signature = 'splicewire:beam:tenancy:pools:role';

    protected $description = 'Create (or reset the password of) the non-owner Postgres role pooled tenants connect as, from beam.tenancy.pooled.rls_user';

    public function handle(): int
    {
        $pools = Tenant::query()->whereNotNull('data->pool')->get()->map(fn (Tenant $tenant) => (string) $tenant->pool)->unique()->values()->all();
        $servers = app(PoolRegistry::class)->serversWithRoles($pools);

        if ($servers === []) {
            $this->error('beam.tenancy.pooled.rls_user.username is unset (BEAM_TENANCY_RLS_USERNAME), and no pool names its own rls_user — nothing to provision.');

            return self::FAILURE;
        }

        foreach ($servers as $connectionName => $roles) {
            foreach ($roles as $role) {
                if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role['username'])) {
                    $this->error("Role name '{$role['username']}' is not a bare Postgres identifier.");

                    return self::FAILURE;
                }

                $this->provision($connectionName, $role['username'], $role['password']);
            }
        }

        return self::SUCCESS;
    }

    protected function provision(string $connectionName, string $username, string $password): void
    {
        $connection = DB::connection($connectionName);
        $exists = (bool) $connection->selectOne('select 1 as ok from pg_roles where rolname = ?', [$username]);
        $quotedName = '"'.str_replace('"', '""', $username).'"';
        $quotedPassword = "'".str_replace("'", "''", $password)."'";

        // Role DDL takes no bind parameters; the name is validated by the caller and the password literal
        // is quoted by doubling — the same discipline rushing/laravel-postgres-rls's Identifier applies.
        $connection->statement($exists
            ? "ALTER ROLE {$quotedName} WITH LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD {$quotedPassword}"
            : "CREATE ROLE {$quotedName} WITH LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD {$quotedPassword}");

        $this->line(($exists ? 'Reset password of existing role ' : 'Created role ').$username." on connection {$connectionName} (LOGIN, no SUPERUSER, no BYPASSRLS).");
    }
}
