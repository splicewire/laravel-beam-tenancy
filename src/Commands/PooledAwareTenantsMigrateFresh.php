<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Symfony\Component\Console\Input\InputOption;

/**
 * stancl's `tenants:migrate-fresh`, refusing pooled tenants (pooled-storage ticket 05). See
 * {@see RefusesPooledTenants}. Not a subclass — stancl declares that command `final` — so the body
 * below is stancl's verbatim (`Stancl\Tenancy\Commands\MigrateFresh::handle()`, ^3.10) behind the
 * refusal, and the container extension swaps this in for the final class by name.
 */
class PooledAwareTenantsMigrateFresh extends Command
{
    use HasATenantsOption, RefusesPooledTenants;

    protected $description = 'Drop all tables and re-run all migrations for tenant(s)';

    public function __construct()
    {
        parent::__construct();

        $this->addOption('--drop-views', null, InputOption::VALUE_NONE, 'Drop views along with tenant tables.', null);
        $this->addOption('--step', null, InputOption::VALUE_NONE, 'Force the migrations to be run so they can be rolled back individually.');
        $this->setName('tenants:migrate-fresh');
    }

    public function handle()
    {
        if ($this->refuseIfPooled('wipe and re-migrate')) {
            return self::FAILURE;
        }

        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) {
            $this->info('Dropping tables.');
            $this->call('db:wipe', array_filter([
                '--database' => 'tenant',
                '--drop-views' => $this->option('drop-views'),
                '--force' => true,
            ]));

            $this->info('Migrating.');
            $this->callSilent('tenants:migrate', [
                '--tenants' => [$tenant->getTenantKey()],
                '--step' => $this->option('step'),
                '--force' => true,
            ]);
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
