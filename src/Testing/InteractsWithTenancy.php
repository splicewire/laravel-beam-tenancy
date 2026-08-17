<?php

namespace Splicewire\Beam\Tenancy\Testing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role as SpatieRole;
use Splicewire\Beam\Tenancy\Domains\NullDomainProvider;
use Splicewire\Beam\Tenancy\Features\TenantModelCatalog;
use Splicewire\Beam\Tenancy\Features\TenantRoleAlias;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Tenancy\PermissionsTenancyBootstrapper;
use Splicewire\Beam\Tenancy\PostgreSQLSchemaManager;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

/**
 * The **tenancy slice of a test harness**, shipped by the package that owns tenancy so a sibling
 * package can write tenant-scoped tests without rebuilding an application.
 *
 * ## Why this exists
 * `splicewire/tower`'s `TenantTestCase` was the estate's only working tenant harness, and it is 1,886
 * lines — 31 `App\` references, ~15 service providers (Sanctum, Prism, AuthVault, Commerce,
 * DataFilters, DataNav …), a co-dev autoload shim, tower's routes, embed morph aliases, host contract
 * bindings. **Almost none of that is tenancy.** The consequence was structural: `beam-embed` and
 * `beam-threads` have ~20 tenant-scoped feature tests living in tower, not because they belong to
 * tower but because tower is the only place the harness existed. A package could not test its own
 * behaviour under tenancy without composing an entire host.
 *
 * What is extracted here is the part tower's own docblock already identified as portable — the tenancy
 * config, whose "every class named is a package class; nothing app-namespaced" is verifiably true — plus
 * the three helpers that config is useless without: the stancl event wiring, the postgres schema
 * rebuild, and a root user.
 *
 * ## The composition seam
 * A consuming package's `TestCase` composes this trait **behind a `trait_exists()` guard**, which is the
 * same auto-adjust shape the estate's providers already use for tenancy — `BeamAccountsServiceProvider`
 * does `class_exists('Splicewire\Beam\Tenancy\BeamTenancyServiceProvider')` for exactly this reason. A
 * package whose host does not install beam-tenancy simply does not get tenant-scoped tests, rather than
 * failing to autoload.
 *
 * There is precedent for a package shipping test apparatus to its siblings: beam-core's
 * `Splicewire\Beam\Doctor\Testing\AssertsStubMigrations`, which every beam-* package's own
 * `*MigrationsAuditTest` consumes. This is that pattern at harness scale.
 *
 * ## What deliberately stays with the consumer
 * The migration paths. {@see centralMigrationPaths()} and {@see tenantMigrationPaths()} default to
 * empty and are meant to be overridden: which migrations constitute "central" and "tenant" is a
 * property of what the host composes, not of tenancy, and guessing it here would silently migrate the
 * wrong estate. tower overrides both with the full ordered app ∪ tower set.
 *
 * ## Honest constraints
 * `rebuildPublicSchema()` is **postgres-specific** — `pg_terminate_backend`, `information_schema`,
 * `DROP SCHEMA … CASCADE`. That is not an oversight: schema-per-tenant is what
 * {@see PostgreSQLSchemaManager} implements, and the sqlite manager below is present only so a
 * config-shape test can boot. A sqlite consumer uses the config half and skips the rebuild.
 */
trait InteractsWithTenancy
{
    /** @var array<string, TenantUser>|null memoized per schema — see {@see rootUser()} */
    protected ?array $rootUsers = null;

    /**
     * The tenancy config. Ported verbatim from tower's harness, where its docblock already recorded the
     * property that makes it portable: every class it names is a package class (`Splicewire\Beam\Tenancy\*`,
     * `Stancl\*`), nothing app-namespaced.
     */
    protected function defineTenancyConfig($app): void
    {
        $c = $app['config'];

        $c->set('tenancy.tenant_model', Tenant::class);
        $c->set('tenancy.id_generator', null);
        $c->set('tenancy.domain_model', Domain::class);
        $c->set('tenancy.domain_providers', [
            'dns' => NullDomainProvider::class,
            'edge' => NullDomainProvider::class,
        ]);
        $c->set('tenancy.central_domains', ['localhost']);
        $c->set('tenancy.bootstrappers', $this->tenancyBootstrappers());
        $c->set('tenancy.database', [
            'central_connection' => 'central',
            'template_tenant_connection' => null,
            'prefix' => 'tenant_',
            'suffix' => '',
            'managers' => [
                'sqlite' => SQLiteDatabaseManager::class,
                'pgsql' => PostgreSQLSchemaManager::class,
            ],
        ]);
        $c->set('tenancy.cache', ['tag_base' => 'tenant']);
        $c->set('tenancy.redis', ['prefix_base' => 'tenant', 'prefixed_connections' => []]);
        $c->set('tenancy.features', [
            TenantConfig::class,
            TenantModelCatalog::class,
            TenantRoleAlias::class,
        ]);
        $c->set('tenancy.routes', false);

        $c->set('tenancy.migration_parameters', [
            '--force' => true,
            '--path' => $this->tenantMigrationPaths(),
            '--realpath' => true,
        ]);
    }

    /**
     * The bootstrapper set. `CircuitTenancyBootstrapper` is deliberately **absent** from the default —
     * hydrating the per-tenant conduit capability overlay pulls a deep chain of host bindings
     * (EntitlementGate, ConduitHydrator, …) that a schema-scoped tenant test does not exercise. A suite
     * testing overlay behaviour overrides this and adds it back, which is the split tower already drew.
     *
     * @return list<class-string>
     */
    protected function tenancyBootstrappers(): array
    {
        return [
            DatabaseTenancyBootstrapper::class,
            CacheTenancyBootstrapper::class,
            QueueTenancyBootstrapper::class,
            PermissionsTenancyBootstrapper::class,
        ];
    }

    /**
     * The stancl core events — the minimal slice a `$tenant->run()` requires: bootstrap on init, revert
     * on end. Without these, entering a tenant context silently does nothing and every assertion inside
     * the closure runs against the central schema, which reads as a passing test.
     */
    protected function wireTenancyEvents(): void
    {
        Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
        Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);
    }

    /**
     * Drop every public table CASCADE, terminate zombie connections and lingering tenant schemas, then
     * run the central estate.
     *
     * The `pg_terminate_backend` step is not defensive noise. Tower's suite currently fails at 421 tests
     * on `no connection to the server`, having leaked **102 test databases against `max_connections=100`
     * — a harness that does not reap its connections takes the whole suite out, and the failure looks
     * like a code defect rather than a harness one.
     */
    protected function rebuildPublicSchema(): void
    {
        foreach (array_keys(config('database.connections')) as $name) {
            DB::connection($name)->disconnect();
        }
        DB::reconnect();

        DB::statement('SELECT pg_terminate_backend(pid)
            FROM pg_stat_activity
            WHERE datname = current_database()
            AND pid <> pg_backend_pid()');

        foreach (DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'") as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS \"{$schema->schema_name}\" CASCADE");
        }

        DB::statement("DO $$ DECLARE r RECORD;
            BEGIN
                FOR r IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public') LOOP
                    EXECUTE 'DROP TABLE IF EXISTS public.' || quote_ident(r.tablename) || ' CASCADE';
                END LOOP;
            END $$;");

        $this->artisan('migrate', [
            '--path' => $this->centralMigrationPaths(),
            '--realpath' => true,
        ]);
    }

    /**
     * A `Root`-roled user in the CURRENT schema context, memoized per schema.
     *
     * Keyed by schema deliberately: the FK a tenant row carries points at the CURRENT schema's `users`
     * table, so this must create the user in whatever context it is called from — central, or inside
     * `$tenant->run()`. Memoizing globally instead would hand a tenant test a central user id and the
     * insert would fail, or worse, succeed against the wrong schema.
     */
    public function rootUser(): TenantUser
    {
        $schema = DB::selectOne('select current_schema() as s')->s;
        $this->rootUsers ??= [];

        if (isset($this->rootUsers[$schema])) {
            return $this->rootUsers[$schema];
        }

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(function_exists('tenant') && tenant() ? tenant('id') : null);
        }

        $user = TenantUser::firstOrCreate(
            ['email' => 'root@app.splicewire.com'],
            ['name' => 'Root', 'password' => bcrypt('password')],
        );

        // Resolve the role class through spatie's config binding rather than naming
        // `Spatie\Permission\Models\Role` directly. `laravel-beam-accounts`' shared
        // `create_permission_tables` migration declares `roles.id` as **uuid** (the cross-host
        // morph-key convention), and spatie's stock model assumes an auto-incrementing integer PK —
        // it generates no identifier, so a direct `SpatieRole::firstOrCreate()` dies on
        // `null value in column "id" of relation "roles" violates not-null constraint`.
        // Hosts bind a `HasUuids` subclass at `permission.models.role` (e.g.
        // `Splicewire\Tower\Models\Role`); this is the same idiom `TeamProvisioner::syncSpatieRole()`
        // already uses. The SpatieRole fallback keeps bigint-keyed harnesses working unchanged.
        $roleClass = config('permission.models.role') ?: SpatieRole::class;

        $roleClass::firstOrCreate(['name' => 'Root', 'guard_name' => 'web']);

        if (! $user->hasRole('Root')) {
            $user->assignRole('Root');
        }

        return $this->rootUsers[$schema] = $user;
    }

    /**
     * The central migration estate. Empty by default — see the trait docblock on why this stays with
     * the consumer rather than being guessed here.
     *
     * @return list<string>
     */
    protected function centralMigrationPaths(): array
    {
        return [];
    }

    /**
     * The tenant migration estate. Empty by default, for the same reason.
     *
     * A note worth carrying, learned expensively in tower: a **shared** migration (`users`,
     * `permission_tables`) must appear in BOTH this list and the central one. Omit it here and no tenant
     * schema ever gets its own `users` table, so every insert inside `$tenant->run()` falls through
     * Postgres's `search_path` to `public.users` and collides with the central row — surfacing as a
     * duplicate-key violation that looks like a test bug rather than a missing path.
     *
     * @return list<string>
     */
    protected function tenantMigrationPaths(): array
    {
        return [];
    }
}
