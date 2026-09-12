<?php

namespace Splicewire\Beam\Tenancy\Tests\Postgres;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Rushing\PostgresRls\PostgresRlsServiceProvider;
use Splicewire\Beam\Tenancy\Destinations\CustomerSuppliedDatabaseDestination;
use Splicewire\Beam\Tenancy\Destinations\GcpCloudSqlDestination;
use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseDestination;
use Splicewire\Beam\Tenancy\HybridPostgresTenantDatabaseManager;
use Splicewire\Beam\Tenancy\Testing\InteractsWithTenancy;
use Splicewire\Beam\Tenancy\Tests\TestCase;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;

/**
 * The Postgres-gated harness (pooled-storage ticket 05). The package suite is sqlite; pooled storage is
 * a Postgres mechanism, so this class re-points the harness at a real database when `PG_TEST_DATABASE`
 * is set and SKIPS — printing why — when it is not. A skip is visible in the run; a silent green is not.
 *
 * Point it at a throwaway, session-scoped database (the isolated-test-db pattern): every table in
 * `public` and every `pool_*` schema is dropped per test.
 */
abstract class PostgresTestCase extends TestCase
{
    use InteractsWithTenancy;

    public const RLS_ROLE = 'beam_tenancy_test_rls';

    protected function setUp(): void
    {
        if (! getenv('PG_TEST_DATABASE')) {
            $this->markTestSkipped('PG_TEST_DATABASE is not set — pooled storage is a Postgres mechanism and this class needs a throwaway Postgres database (PG_TEST_HOST/PORT/USERNAME/PASSWORD optional).');
        }

        parent::setUp();

        // The hybrid manager is resolved from the container here (stancl's `manager()`), which pulls in
        // the three Isolated Database destinations — one of them needs a host-only OIDC signing key.
        // The pooled branch never touches a destination, so they are inert fakes in this harness; a
        // call on one is a failure, not a silent pass.
        foreach ([IsolatedDatabaseDestination::class, CustomerSuppliedDatabaseDestination::class, GcpCloudSqlDestination::class] as $destination) {
            $this->app->instance($destination, Mockery::mock($destination));
        }

        // stancl runs its bootstrappers from listeners its own provider registers, and binds `Tenancy`
        // as a singleton; this harness boots no stancl provider, so the shared trait wires both.
        $this->wireTenancyEvents();
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PostgresRlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (! getenv('PG_TEST_DATABASE')) {
            return;
        }

        $owner = [
            'driver' => 'pgsql',
            'host' => getenv('PG_TEST_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PG_TEST_PORT') ?: 5432),
            'database' => getenv('PG_TEST_DATABASE'),
            'username' => getenv('PG_TEST_USERNAME') ?: 'postgres',
            'password' => getenv('PG_TEST_PASSWORD') ?: '',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];

        $app['config']->set('database.default', 'central');
        $app['config']->set('database.connections.central', $owner);
        $app['config']->set('database.connections.pgsql', $owner);
        $app['config']->set('database.connections.testing', $owner);
        $app['config']->set('tenancy.database.central_connection', 'central');
        $app['config']->set('tenancy.database.managers', ['pgsql' => HybridPostgresTenantDatabaseManager::class]);
        $app['config']->set('tenancy.bootstrappers', [DatabaseTenancyBootstrapper::class]);
        $app['config']->set('tenancy.migration_parameters', [
            '--force' => true,
            '--path' => [realpath(__DIR__.'/../Fixtures/pool-migrations')],
            '--realpath' => true,
        ]);
        $app['config']->set('beam.tenancy.pooled.rls_user', ['username' => self::RLS_ROLE, 'password' => '']);
        $app['config']->set('beam.tenancy.pooled.force_rls', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (DB::select("select schema_name from information_schema.schemata where schema_name like 'pool\\_%'") as $row) {
            DB::statement('drop schema "'.$row->schema_name.'" cascade');
        }

        // Every table in `public` — the harness re-creates its own each test.
        Schema::dropAllTables();

        DB::statement("do $$ begin if not exists (select 1 from pg_roles where rolname = '".self::RLS_ROLE."') then create role ".self::RLS_ROLE.' login; end if; end $$');

        parent::defineDatabaseMigrations();
    }
}
