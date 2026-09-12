<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\MigrateCommand;
use Stancl\Tenancy\Commands\Migrate;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\MigratingDatabase;

/**
 * stancl's `tenants:migrate`, minus the pooled tenants (pooled-storage ticket 05).
 *
 * A pool is migrated ONCE, as the owner role, by `splicewire:beam:tenancy:pools:migrate`. Running the
 * per-tenant migrator inside a pooled tenant's frame would run every migration N times against one
 * schema (harmless but wasteful) and — the real hazard — run any data backfill a migration carries
 * INSIDE ONE TENANT'S FRAME, where the row-level-security policy scopes it to that tenant's rows. The
 * other tenants' rows would stay un-backfilled with a green exit code. So pooled tenants are filtered
 * out here and named on the console, and the pool command is what migrates them.
 *
 * ⚠️ stancl's `runForMultiple()` reads an EMPTY list as "every tenant" (`$tenants ?: cursor()`), so a
 * `--tenants=<pooled>` invocation whose filtered list is empty must return before calling it — the
 * fan-out it would otherwise trigger is the exact thing `DemoTenantSeeder` warns about.
 *
 * Installed by `app()->extend(Migrate::class, …)` rather than a second `commands()` registration,
 * because provider boot order decides which of two same-named registrations Artisan keeps, and a
 * container extension applies whichever provider booted first.
 */
class PooledAwareTenantsMigrate extends Migrate
{
    public function handle()
    {
        foreach (config('tenancy.migration_parameters') as $parameter => $value) {
            if (! $this->input->hasParameterOption($parameter)) {
                $this->input->setOption(ltrim($parameter, '-'), $value);
            }
        }

        if (! $this->confirmToProceed()) {
            return;
        }

        // Eager: the lazy cursor cannot be partitioned and then iterated twice.
        [$pooled, $others] = $this->getTenants()->collect()->partition(fn ($tenant) => method_exists($tenant, 'isPooled') && $tenant->isPooled());

        foreach ($pooled as $tenant) {
            $this->line("Tenant: {$tenant->getTenantKey()} — pooled ({$tenant->poolSchema()}); skipped, migrate the pool with splicewire:beam:tenancy:pools:migrate");
        }

        $others = $others->values();

        if ($others->isEmpty()) {
            return;
        }

        tenancy()->runForMultiple($others, function ($tenant) {
            $this->line("Tenant: {$tenant->getTenantKey()}");

            event(new MigratingDatabase($tenant));

            // The grandparent's handle — the actual migration run on the (now tenant) default
            // connection. `parent::handle()` would be stancl's loop, which is what this replaces.
            MigrateCommand::handle();

            event(new DatabaseMigrated($tenant));
        });
    }
}
