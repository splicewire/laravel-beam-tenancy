<?php

namespace Splicewire\Beam\Tenancy\Tests\Postgres;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder;

/**
 * DemoTenantSeeder::tenantHoldsUsers() on Postgres (launch G6 review-r1, 2026-10-17): the seeder seats a subject in the
 * tenant only when the tenant's OWN schema holds `users`. The trap is a tenant session whose `search_path` still
 * reaches `public`: anything that resolves the bare name `users` through it answers from the central `public.users`,
 * and would seat into a schema with no users table. Each case moves the session to "<tenant schema>, public" with
 * `public.users` present (this harness rebuilds the central estate per test).
 */
class DemoTenantSeederTenantUsersTest extends PostgresTestCase
{
    /** A tenant whose run() enters `$connection` as the default connection, the way stancl's database bootstrapper does. */
    private function tenantOn(string $connection): Model
    {
        return new class($connection) extends Model
        {
            public function __construct(private string $tenantConnection = 'central')
            {
                parent::__construct();
            }

            public function run(Closure $callback): mixed
            {
                $central = DB::getDefaultConnection();
                DB::setDefaultConnection($this->tenantConnection);

                try {
                    return $callback();
                } finally {
                    DB::setDefaultConnection($central);
                }
            }
        };
    }

    private function seeder(): DemoTenantSeeder
    {
        return new class extends DemoTenantSeeder
        {
            public function holdsUsers(Model $tenant): bool
            {
                return $this->tenantHoldsUsers($tenant);
            }
        };
    }

    /**
     * A connection CONFIGURED as central (`search_path` public) whose SESSION is moved onto `$schema` with `SET
     * search_path`, the way a tenant is entered on a shared connection. Laravel's `hasTable` resolves an unqualified
     * name against the CONFIGURED first schema, so here it answers from `public.users`; `current_schema()` reads the
     * live session.
     */
    private function tenantConnection(string $schema, bool $withUsers): string
    {
        DB::statement('create schema "'.$schema.'"');
        if ($withUsers) {
            DB::statement('create table "'.$schema.'".users (id uuid primary key, name text, email text, password text)');
        }

        $name = 'probe_'.$schema;
        config(['database.connections.'.$name => config('database.connections.central')]);
        DB::purge($name);
        DB::connection($name)->statement('set search_path to "'.$schema.'", public');

        return $name;
    }

    public function test_a_tenant_schema_without_users_is_not_seated_even_though_public_users_is_reachable(): void
    {
        $connection = $this->tenantConnection('pool_g6_nousers', withUsers: false);

        // The trap is live: name resolution through the session's search_path reaches `public.users` from this tenant.
        // (Laravel 13's `hasTable` already compiles to `current_schema()` and is not fooled; a check that RESOLVES the
        // name, like `to_regclass('users')`, is, and this case fails for it.)
        $this->assertSame('pool_g6_nousers', DB::connection($connection)->selectOne('select current_schema() as s')->s);
        $this->assertSame('users', DB::connection($connection)->selectOne("select to_regclass('users')::text as t")->t);

        $this->assertFalse($this->seeder()->holdsUsers($this->tenantOn($connection)));
    }

    public function test_a_tenant_schema_with_its_own_users_is_seated(): void
    {
        $connection = $this->tenantConnection('pool_g6_users', withUsers: true);

        $this->assertTrue($this->seeder()->holdsUsers($this->tenantOn($connection)));
    }
}
