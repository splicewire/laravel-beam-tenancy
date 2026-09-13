<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rushing\PostgresRls\RowLevelSecurity\RoleBinder;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * Issue or revoke a pooled tenant's direct-access Postgres role (pooled-storage ticket 10) — a role
 * whose own `ALTER ROLE ... SET` binds the session setting to that tenant's key, so a direct
 * connection as it (a BI tool, `psql`) sees exactly that tenant's rows under the same policy, with
 * no application in the path. See {@see \Rushing\PostgresRls\RowLevelSecurity\RoleBinder}.
 *
 * Gated by `beam.tenancy.pooled.direct_access_roles` — off by default, because a role per pooled
 * tenant is a real credential surface a host opts INTO, never a default every pooled tenant carries.
 *
 * The tenant keeps only the ROLE NAME ({@see Tenant::markDirectAccessRole()}). The password is
 * generated here, printed ONCE, and stored nowhere — not on the tenant, not in a log, not in this
 * command's own state. A host that loses it re-runs the command, which rotates the password on the
 * existing role rather than erroring.
 */
class PoolsDirectAccess extends Command
{
    protected $signature = 'splicewire:beam:tenancy:pools:direct-access {tenant : Tenant id or slug} {--revoke : Revoke and drop the role instead of issuing one}';

    protected $description = 'Issue (or revoke) a pooled tenant\'s direct-access Postgres role — a role per key, bound by ALTER ROLE ... SET';

    public function handle(): int
    {
        if (! config('beam.tenancy.pooled.direct_access_roles', false)) {
            $this->error('beam.tenancy.pooled.direct_access_roles is off — set BEAM_TENANCY_DIRECT_ACCESS_ROLES=true to enable direct-access roles on this host.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($this->argument('tenant')) ?? Tenant::where('slug', $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error("No tenant found for '{$this->argument('tenant')}'.");

            return self::FAILURE;
        }

        if (! $tenant->isPooled()) {
            $this->error("Tenant '{$tenant->getTenantKey()}' is not on Pooled storage — a direct-access role scopes a pool's row-level-security policy, and this tenant has none.");

            return self::FAILURE;
        }

        return $this->option('revoke') ? $this->revoke($tenant) : $this->issue($tenant);
    }

    protected function issue(Tenant $tenant): int
    {
        $role = $tenant->direct_access_role ?? $this->roleNameFor($tenant);
        $password = Str::password(32, symbols: false);
        $setting = (string) config('beam.tenancy.pooled.session_setting', 'app.tenant_id');
        $connectionName = 'direct_access_bind_'.$tenant->getTenantKey();

        $this->registerOwnerConnection($connectionName, $tenant);

        try {
            (new RoleBinder(DB::connection($connectionName), (string) $tenant->poolSchema()))
                ->bind($role, $setting, (string) $tenant->getTenantKey(), $password);
        } finally {
            TenancyConnections::forget($connectionName);
        }

        $tenant->markDirectAccessRole($role)->save();

        $this->info("Role \"{$role}\" is bound to tenant '{$tenant->getTenantKey()}' (setting {$setting} = '{$tenant->getTenantKey()}').");
        $this->line('Password (shown once, stored nowhere): '.$password);

        return self::SUCCESS;
    }

    protected function revoke(Tenant $tenant): int
    {
        if (! $tenant->hasDirectAccessRole()) {
            $this->error("Tenant '{$tenant->getTenantKey()}' has no direct-access role to revoke.");

            return self::FAILURE;
        }

        $role = (string) $tenant->direct_access_role;
        $connectionName = 'direct_access_unbind_'.$tenant->getTenantKey();

        $this->registerOwnerConnection($connectionName, $tenant);

        try {
            (new RoleBinder(DB::connection($connectionName), (string) $tenant->poolSchema()))->unbind($role);
        } finally {
            TenancyConnections::forget($connectionName);
        }

        $tenant->markDirectAccessRole(null)->save();

        $this->info("Role \"{$role}\" revoked and dropped for tenant '{$tenant->getTenantKey()}'.");

        return self::SUCCESS;
    }

    /**
     * A plain identifier, deterministic for the tenant, under Postgres's 63-byte cap — the same
     * grammar `PoolMigrator::schemaNameFor()` enforces for a schema name, applied here to a role.
     */
    protected function roleNameFor(Tenant $tenant): string
    {
        $slug = Str::of((string) $tenant->getTenantKey())->slug('_')->limit(50, '')->toString();

        return 'direct_'.$slug;
    }

    /** An owner-privileged connection on the pool's server, search_path pointed at the pool schema. */
    protected function registerOwnerConnection(string $connectionName, Tenant $tenant): void
    {
        $schema = (string) $tenant->poolSchema();
        $server = app(\Splicewire\Beam\Tenancy\Pools\PoolRegistry::class)->connectionFor((string) $tenant->pool);
        $template = (array) Config::get("database.connections.{$server}");
        $template['search_path'] = "{$schema},public";
        unset($template['session_settings']);
        Config::set("database.connections.{$connectionName}", $template);
        DB::purge($connectionName);
    }
}
