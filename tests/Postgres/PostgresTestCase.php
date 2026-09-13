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

    /** A second database on the same server, standing in for a second pool server (ticket 13). */
    public const REMOTE_CONNECTION = 'pool_remote';

    public static function remoteDatabase(): string
    {
        return getenv('PG_TEST_DATABASE').'_pool2';
    }

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

        // Tenant status markers (markProvisioning / markFailed / markActive) go through beam-workflows'
        // Status facade, whose provider this harness does not boot. Bound the way that provider binds
        // them, so a job that marks progress runs here as it does at a host (pools:move, ticket 13).
        $this->app->singleton(\Splicewire\Beam\Workflows\Display\StatusEmitter::class, fn ($app) => new \Splicewire\Beam\Workflows\Display\StatusEmitter($app['config'], fn () => $app['events']));
        $this->app->singleton(\Splicewire\Beam\Workflows\Display\StatusManager::class, fn ($app) => new \Splicewire\Beam\Workflows\Display\StatusManager($app->make(\Splicewire\Beam\Workflows\Display\StatusEmitter::class)));
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
        $app['config']->set('database.connections.'.self::REMOTE_CONNECTION, array_merge($owner, ['database' => self::remoteDatabase()]));
        $app['config']->set('beam.tenancy.pooled.pools', ['remote' => ['connection' => self::REMOTE_CONNECTION]]);
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

        // The second "server": created once (left in place — reap it with the session database), its pool
        // schemas cleared per test.
        if (DB::selectOne('select 1 as ok from pg_database where datname = ?', [self::remoteDatabase()]) === null) {
            DB::statement('create database "'.self::remoteDatabase().'"');
        }
        foreach (DB::connection(self::REMOTE_CONNECTION)->select("select schema_name from information_schema.schemata where schema_name like 'pool\\_%'") as $row) {
            DB::connection(self::REMOTE_CONNECTION)->statement('drop schema "'.$row->schema_name.'" cascade');
        }
        DB::purge(self::REMOTE_CONNECTION);

        DB::statement("do $$ begin if not exists (select 1 from pg_roles where rolname = '".self::RLS_ROLE."') then create role ".self::RLS_ROLE.' login; end if; end $$');
        // Default privileges on `public` outlive the tables dropped above; reset them so a test proves its own
        // central grant rather than inheriting an earlier test's (pools:migrate's central_access).
        DB::statement('alter default privileges in schema public revoke all on tables from '.self::RLS_ROLE);
        DB::statement('alter default privileges in schema public revoke all on sequences from '.self::RLS_ROLE);

        parent::defineDatabaseMigrations();

        // Tenant status markers write the Display timeline to `activity_log` (central). The sqlite
        // harness never needs it; a job that marks progress does (ticket 13).
        Schema::create('activity_log', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }
}
