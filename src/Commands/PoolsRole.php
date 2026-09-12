<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;

/**
 * Provision the NON-OWNER Postgres role pooled tenant connections authenticate as (pooled-storage
 * ticket 07). Once per cluster, by an operator or a stamp's setup — never by a request.
 *
 * Reads `beam.tenancy.pooled.rls_user` (username + password) and, on the central connection (whose
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
        $username = (string) Config::get('beam.tenancy.pooled.rls_user.username', '');
        $password = (string) Config::get('beam.tenancy.pooled.rls_user.password', '');

        if ($username === '') {
            $this->error('beam.tenancy.pooled.rls_user.username is unset (BEAM_TENANCY_RLS_USERNAME) — nothing to provision.');

            return self::FAILURE;
        }

        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $username)) {
            $this->error("Role name '{$username}' is not a bare Postgres identifier.");

            return self::FAILURE;
        }

        $connection = DB::connection(TenancyConnections::central());
        $exists = (bool) $connection->selectOne('select 1 as ok from pg_roles where rolname = ?', [$username]);
        $quotedName = '"'.str_replace('"', '""', $username).'"';
        $quotedPassword = "'".str_replace("'", "''", $password)."'";

        // Role DDL takes no bind parameters; the name is validated above and the password literal is
        // quoted by doubling — the same discipline rushing/laravel-postgres-rls's Identifier applies.
        $connection->statement($exists
            ? "ALTER ROLE {$quotedName} WITH LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD {$quotedPassword}"
            : "CREATE ROLE {$quotedName} WITH LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD {$quotedPassword}");

        $this->line(($exists ? 'Reset password of existing role ' : 'Created role ').$username.' (LOGIN, no SUPERUSER, no BYPASSRLS).');

        return self::SUCCESS;
    }
}
