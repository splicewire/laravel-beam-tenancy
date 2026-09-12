<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Splicewire\Beam\Tenancy\Pools\PoolMigrator;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * Migrate and prepare pool schemas — once per pool, as the owner (pooled-storage ticket 05). See
 * {@see PoolMigrator} for the sequence and why it never runs inside a tenant frame.
 *
 * With no argument, every pool any tenant is marked with plus the configured default pool.
 */
class PoolsMigrate extends Command
{
    protected $signature = 'splicewire:beam:tenancy:pools:migrate {pool? : One pool name; omit for every known pool}';

    protected $description = 'Create, migrate and prepare pooled-storage schemas (once per pool, as the owner role)';

    public function handle(PoolMigrator $migrator): int
    {
        $pools = $this->argument('pool') !== null
            ? [(string) $this->argument('pool')]
            : $this->knownPools();

        foreach ($pools as $pool) {
            $report = $migrator->migrate($pool);
            $this->line("Pool: {$pool} ({$migrator->schemaFor($pool)}) — ".($report->isNoop() ? 'prepared, nothing to apply' : "{$report->appliedCount()} statement(s) applied, {$report->skippedCount()} skipped"));
            foreach ($report->lines() as $line) {
                $this->line('  '.$line);
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    protected function knownPools(): array
    {
        $marked = Tenant::query()->whereNotNull('data->pool')->get()
            ->map(fn (Tenant $tenant) => (string) $tenant->pool)
            ->filter()
            ->all();

        return array_values(array_unique([...$marked, (string) config('beam.tenancy.pooled.default_pool', 'default')]));
    }
}
