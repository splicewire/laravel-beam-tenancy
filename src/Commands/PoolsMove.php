<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Splicewire\Beam\Tenancy\Pools\TenantPoolMover;
use Splicewire\Beam\Tenancy\Tenant;
use Throwable;

/**
 * Move a pooled tenant to another pool, on the same database server or another one
 * (pooled-storage ticket 13). See {@see TenantPoolMover} for the order of operations and what a
 * failure at each point leaves behind.
 */
class PoolsMove extends Command
{
    protected $signature = 'splicewire:beam:tenancy:pools:move {tenant : Tenant id or slug} {pool : Target pool name}';

    protected $description = 'Move a pooled tenant into another pool (same server or another), copying its rows and cutting over';

    public function handle(TenantPoolMover $mover): int
    {
        $tenant = Tenant::find($this->argument('tenant')) ?? Tenant::where('slug', $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error("No tenant found for '{$this->argument('tenant')}'.");

            return self::FAILURE;
        }

        $from = (string) $tenant->pool;

        try {
            $moved = $mover->move($tenant, (string) $this->argument('pool'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant '{$tenant->getTenantKey()}' moved from pool '{$from}' to pool '{$this->argument('pool')}': ".array_sum($moved).' row(s) across '.count(array_filter($moved)).' table(s).');

        return self::SUCCESS;
    }
}
