<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Splicewire\Beam\Tenancy\Seats\WorkspaceSeats;
use Splicewire\Beam\Tenancy\Tenant;
use Throwable;

/**
 * Re-seat every live workspace seat ({@see WorkspaceSeats}): the backfill for seats made before acceptance and tier
 * changes wrote the tenant mirror and role (launch row qitem-20261021011058-9fa6ba0e). A host runs it once; a fresh
 * install never needs it. A command, not a migration that iterates tenants (docs/agents/traps/tenancy.md).
 *
 * Each seat is planned and written inside its own tenant's context (`WorkspaceSeats` runs `$tenant->run()`), so the
 * connection is the one that tenant resolves to at the moment of the write, never the central one. A tenant that throws
 * is reported and the rest continue: one bad row must not abort the run into a plausible partial result.
 *
 * `--dry-run` lists what would change and writes nothing. Idempotent: a second run changes nothing.
 */
class ReseatWorkspaceMembers extends Command
{
    protected $signature = 'splicewire:beam:tenancy:reseat {--dry-run : List what would change; write nothing}';

    protected $description = 'Re-seat every live workspace member: mirror them into their tenant and give them exactly their tier\'s role';

    public function handle(WorkspaceSeats $seats): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $failed = [];

        foreach (Tenant::query()->cursor() as $tenant) {
            try {
                foreach ($tenant->users()->wherePivotNull('removed_at')->get() as $member) {
                    $tier = (string) $member->getRelationValue('pivot')?->getAttribute('role');
                    $plan = $seats->plan($tenant, $member, $tier);
                    if ($plan === []) {
                        continue;
                    }
                    $changed++;
                    $this->line(sprintf('  %s %s [%s]: %s', $dryRun ? 'would' : 'seated', $tenant->getTenantKey(), $member->getAttribute('email'), implode(' ', $plan)));
                    if (! $dryRun) {
                        $seats->seat($tenant, $member, $tier);
                    }
                }
            } catch (Throwable $e) {
                $failed[] = $tenant->getTenantKey();
                $this->error("  tenant {$tenant->getTenantKey()} failed: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('%s %d seat(s)%s.', $dryRun ? 'Would change' : 'Changed', $changed, $failed === [] ? '' : '; failed tenants: '.implode(', ', $failed)));

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
