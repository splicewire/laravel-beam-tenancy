<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * A tenant of a host that owns `users` CENTRAL-only (a Commerce-shaped starter: beam-accounts' `publish_auth_migrations`
 * 'absent'): its run() enters a tenant connection that holds the permission tables but no `users`, the way stancl's
 * database bootstrapper makes the tenant connection the default.
 */
class CentralUsersTenant extends Tenant
{
    public static string $tenantConnection = 'tenant_without_users';

    public function run(callable $callback)
    {
        $central = DB::getDefaultConnection();
        DB::setDefaultConnection(static::$tenantConnection);

        try {
            return $callback($this);
        } finally {
            DB::setDefaultConnection($central);
        }
    }
}
