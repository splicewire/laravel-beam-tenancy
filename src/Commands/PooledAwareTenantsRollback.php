<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Stancl\Tenancy\Commands\Rollback;

/** stancl's `tenants:rollback`, refusing pooled tenants (pooled-storage ticket 05). See {@see RefusesPooledTenants}. */
class PooledAwareTenantsRollback extends Rollback
{
    use RefusesPooledTenants;

    public function handle()
    {
        if ($this->refuseIfPooled('roll back')) {
            return self::FAILURE;
        }

        return parent::handle();
    }
}
