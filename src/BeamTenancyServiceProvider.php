<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rushing\AuthVault\Contracts\VaultContextGuard;
use Rushing\SaloonKernel\Contracts\KernelContextBridge;
use Rushing\Popcorn\Laravel\Runner\NullRunner;
use Rushing\Popcorn\Registries\Registrars\ConfigRegistrar;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tenancy\Contracts\TenantDnsProvider;
use Splicewire\Beam\Tenancy\Contracts\TenantEdgeProvider;
use Splicewire\Beam\Tenancy\Domains\NullDomainProvider;
use Splicewire\Beam\Tenancy\Loopback\TenancyContextBridge;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Provision\Gcp\WorkloadIdentityCredentialResolver;
use Splicewire\Beam\Provision\Tofu\CabTokenMinter;
use Splicewire\Beam\Provision\Tofu\TenantDatabaseRootConfigRenderer;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;
use Splicewire\Beam\Provision\Tofu\TofuModulesPath;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Sitemap\Resolvers\ConfigSitemapBaseUrlResolver;
use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Surgeon\AuditScanPaths;
use Splicewire\Beam\Tenancy\Commands\PooledAwareTenantsMigrate;
use Splicewire\Beam\Tenancy\Commands\PooledAwareTenantsMigrateFresh;
use Splicewire\Beam\Tenancy\Commands\PooledAwareTenantsRollback;
use Splicewire\Beam\Tenancy\Commands\PoolsDirectAccess;
use Splicewire\Beam\Tenancy\Commands\PoolsMigrate;
use Splicewire\Beam\Tenancy\Commands\PoolsMove;
use Splicewire\Beam\Tenancy\Commands\PoolsRole;
use Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder;
use Splicewire\Beam\Tenancy\Destinations\CustomerSuppliedDatabaseDestination;
use Splicewire\Beam\Tenancy\Destinations\GcpCloudSqlDestination;
use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseDestination;
use Splicewire\Beam\Tenancy\Doctor\BeamTenancyMigrationsAudit;
use Splicewire\Beam\Tenancy\Doctor\MachineIdentityOnMembershipPivotAudit;
use Splicewire\Beam\Tenancy\Doctor\PooledStorageAudit;
use Splicewire\Beam\Tenancy\Doctor\PooledStorageFrameAudit;
use Splicewire\Beam\Tenancy\Doctor\PooledTenantMarkersAudit;
use Splicewire\Beam\Tenancy\Http\Middleware\InitializeTenancyBySoleTenant;
use Splicewire\Beam\Tenancy\Listeners\MachineIdentityAwareUpdateSyncedResource;
use Splicewire\Beam\Tenancy\MachineIdentity\MachineIdentityKind;
use Splicewire\Beam\Tenancy\MachineIdentity\MachineIdentityKindRegistry;
use Splicewire\Beam\Tenancy\Provisioning\TenantProvisioningStepRegistry;
use Splicewire\Beam\Tenancy\Sitemap\TenantSitemapBaseUrlResolver;
use Splicewire\Beam\Tenancy\Support\StanclTenancyContext;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;
use Splicewire\Beam\Tenancy\Vault\TenancyVaultContextGuard;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;
use Stancl\Tenancy\Middleware;

/**
 * The PURE-tenancy substrate the whole estate FKs to: `tenants` (stancl core) + `domains`,
 * `tenant_users`. Each create is squashed pre-prod (no deployed data to
 * preserve migration history for) with its own additive columns folded straight in
 * (`tenants.parent_tenant_id`, `tenant_users.removed_at`, `domains.is_primary`) — see each stub's
 * own docblock for what it carries. Billing identity (Cashier's Stripe columns) moved OFF `tenants`
 * entirely to `laravel-beam-commerce`'s polymorphic `beam_billable` table.
 *
 * These ship as PUBLISH-ONLY spatie/laravel-package-tools stubs — the idiomatic pattern for a
 * PackageServiceProvider. `runsMigrations` stays FALSE (the package-tools default), so beam-tenancy
 * never loads them at runtime; `vendor:publish --tag=beam-tenancy-migrations` (or
 * `splicewire:beam:install`) re-stamps + sequences timestamped copies into the HOST at install time,
 * which runs them.
 *
 * CENTRAL ONLY — these live on the central connection, so only the central (flat) stub ships for each
 * (no `tenant/` twin: a `tenants` table inside a tenant schema would be wrong). Every downstream FK to
 * `tenants` — including the app-owned `tenant_syncs` table — resolves once the timestamp assigned at
 * publish time sorts these ahead of it, exactly like any other beam-* substrate table (the estate's
 * decided publish-only stub convention; no special-cased epoch-prefix workaround).
 *
 * Federation tables (tenant_syncs + scaffold_packs cross-cut) stay app-side.
 */
class BeamTenancyServiceProvider extends PackageServiceProvider
{
    /**
     * Pin each `database`-driver cache store that names no connection to the central connection.
     *
     * A `database` cache store with `connection => null` resolves the DEFAULT connection when it is first built, and
     * database tenancy switches the default to `tenant` inside `$tenant->run()`. Every tenancy host in the estate keeps
     * its `cache` and `cache_locks` tables central only (measured 2026-10-16: satellite, tower, the flagship and the five
     * starters; none has a tenant cache migration), so the first cache use inside a tenant context hit
     * `relation "cache" does not exist`. On the market deploy that was spatie/laravel-permission clearing its cache when
     * DemoTenantSeeder::seatInTenant() created the tenant Admin role, which aborted `splicewire:beam:install` (launch
     * row qitem-20261016172734-edfd969d; G6).
     *
     * Pinned at register time because that is when the default IS central; read later, the default may already be the
     * tenant's (the trap {@see TenancyConnections::central()} documents). A store that names its own connection keeps it,
     * and an unset lock connection follows that store connection (as Laravel's own `lock_connection ?? connection`
     * does); non-database stores are untouched.
     *
     * @param  array<string, mixed>  $stores  `config('cache.stores')`
     * @return array<string, mixed>
     */
    public static function pinCentralDatabaseCacheStores(array $stores, string $central): array
    {
        foreach ($stores as $name => $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
                continue;
            }

            // Locks follow the store's own connection (Laravel resolves `lock_connection ?? connection`), so an unset
            // lock connection is pinned to whatever the store ends up on: the host's choice, or central.
            if (($store['connection'] ?? null) === null || $store['connection'] === '') {
                $stores[$name]['connection'] = $central;
            }

            if (($store['lock_connection'] ?? null) === null || $store['lock_connection'] === '') {
                $stores[$name]['lock_connection'] = $stores[$name]['connection'];
            }
        }

        return $stores;
    }

    public function register(): void
    {
        // pooled-storage ticket 05: stancl's `tenants:migrate` learns to skip pooled tenants. A
        // container EXTENSION rather than a second `commands()` registration, because Artisan keeps
        // whichever same-named registration booted last and provider order is not this package's to
        // decide; `extend` applies at resolve time whatever the order.
        $this->app->extend(\Stancl\Tenancy\Commands\Migrate::class, fn ($command, $app) => $app->make(PooledAwareTenantsMigrate::class));
        // …and its two destructive siblings REFUSE a pooled tenant: a wipe or rollback of the tenant
        // connection is a wipe or rollback of the whole pool.
        $this->app->extend(\Stancl\Tenancy\Commands\Rollback::class, fn ($command, $app) => $app->make(PooledAwareTenantsRollback::class));
        $this->app->extend(\Stancl\Tenancy\Commands\MigrateFresh::class, fn ($command, $app) => $app->make(PooledAwareTenantsMigrateFresh::class));

        parent::register();

        $this->registerMachineIdentityKinds();
        $this->registerProvisioningSteps();
        $this->registerMachineIdentityAwareSyncListener();
        $this->registerTenancyContext();

        // Launch row qitem-20261016172734-edfd969d: pin every `database` cache store that names no connection to the
        // CENTRAL connection, now, while the default still is central. After registerTenancyContext(): central() resolves through this package's TenancyContext adapter. See {@see pinCentralDatabaseCacheStores()}.
        $stores = $this->app['config']->get('cache.stores', []);
        $central = TenancyConnections::central() ?? $this->app['config']->get('database.default');

        if (is_array($stores) && is_string($central) && $central !== '') {
            $this->app['config']->set('cache.stores', self::pinCentralDatabaseCacheStores($stores, $central));
        }
        $this->registerDomainProviderDefaults();
        $this->registerKernelContextBridge();
        $this->registerVaultContextGuard();

        $this->app->singleton(IsolatedDatabaseDestination::class, function ($app) {
            $config = $app['config']->get('beam.tenancy.isolated_database', []);

            return new IsolatedDatabaseDestination(
                cloudBinary: $config['cloud_binary'] ?? 'cloud',
                apiToken: $config['api_token'] ?? null,
                region: $config['region'] ?? 'us-east-1',
                clusterType: $config['cluster_type'] ?? 'neon_serverless_postgres_18',
                extensions: $config['extensions'] ?? ['vector', 'fuzzystrmatch'],
                cliHomeDir: $config['cli_home'] ?? storage_path('app/laravel-cloud-cli'),
            );
        });

        $this->app->singleton(CustomerSuppliedDatabaseDestination::class, function ($app) {
            $config = $app['config']->get('beam.tenancy.isolated_database', []);

            return new CustomerSuppliedDatabaseDestination(
                extensions: $config['extensions'] ?? ['vector', 'fuzzystrmatch'],
            );
        });

        $this->app->singleton(GcpCloudSqlDestination::class, function ($app) {
            $config = $app['config']->get('beam.tenancy.gcp_cloud_sql', []);
            $modulesDir = TofuModulesPath::dir();
            $beamProvisionConfig = $app['config']->get('beam.provision', []);

            $tofu = new TofuApplyDispatcher(
                runner: new NullRunner,
                tokenMinter: new CabTokenMinter,
                stateBucket: $beamProvisionConfig['state_bucket'] ?? 'splicewire-beam-tofu-state',
                modulesDir: $modulesDir,
                pluginCacheDir: $beamProvisionConfig['plugin_cache_dir'] ?? null,
                wallMs: $beamProvisionConfig['apply_wall_ms'] ?? 900_000,
            );

            return new GcpCloudSqlDestination(
                project: $config['project'] ?? 'splicewire',
                region: $config['region'] ?? 'us-central1',
                serviceAccountEmail: $config['service_account_email'] ?? 'beam-provision@splicewire.iam.gserviceaccount.com',
                workloadIdentityProvider: $config['workload_identity_provider'] ?? '',
                modulesDir: $modulesDir,
                rootConfigsRoot: $beamProvisionConfig['root_configs_root'] ?? sys_get_temp_dir().'/beam-tofu-root-configs',
                extensions: $config['extensions'] ?? ['vector', 'fuzzystrmatch'],
                authorizedNetworks: $config['authorized_networks'] ?? [],
                identityTokenMinter: $app->make(IdentityTokenMinter::class),
                credentialResolver: new WorkloadIdentityCredentialResolver,
                renderer: new TenantDatabaseRootConfigRenderer($modulesDir),
                tofu: $tofu,
            );
        });
    }

    /**
     * Bind {@see MachineIdentityKindRegistry} and seed the two kinds this package owns.
     *
     * REGISTER phase, deliberately, and for the same reason beam-lineage binds its kind registry
     * there: tower registers `broker` and a host registers its own from their BOOT phases, and every
     * provider's register phase completes before any boot phase runs. Binding here is what guarantees
     * a later registration has a registry to land in, whatever the provider order.
     *
     * The host's own kinds arrive through a {@see ConfigRegistrar} over
     * `beam.tenancy.machine_identity.kinds`, attached inside the singleton so config is read at
     * resolve time rather than frozen at provider construction. `Filled` fills at attach and
     * `OnKeyDuplicate::Supersede` means a later hand-registration wins — so a host that wants to
     * override `sync` declares it in config or registers it later, and does not have to fight this
     * package for the key.
     *
     * ## Both shipped kinds are now MEASURED, and they measured to different things
     *
     * `sync` carries a real list; `system` carries an explicitly empty one. Those are two different
     * claims and {@see MachineIdentityKind} now spells them differently from `null`/UNMEASURED — see
     * that class's table. Neither list is a guess: each entry below cites where it was read off the
     * estate. Do not add an ability here that nothing enforces.
     */
    /**
     * Bind {@see TenantProvisioningStepRegistry}.
     *
     * ⚠️ A SINGLETON, and that is the whole point of binding it at all. An unbound registry stays
     * auto-resolvable, so `app()` hands back a FRESH, empty instance on every call — the engine tier
     * would register its pipeline into one object and every caller would read another. Nothing would
     * error; provisioning would simply run zero steps. This estate's signature defect, and the reason
     * `TenantProvisioningStepRegistryTest` asserts `app($c) === app($c)` rather than only that a
     * registry resolves.
     *
     * This package ships NO default pipeline. The steps are engine-tier classes that beam-tenancy
     * cannot see (`Splicewire\Tower\Provisioning\*`), so a default here could only be a guess or an
     * empty list presented as an answer. The tier that owns the steps registers them.
     */
    protected function registerProvisioningSteps(): void
    {
        $this->app->singleton(TenantProvisioningStepRegistry::class, fn () => new TenantProvisioningStepRegistry);
    }

    protected function registerMachineIdentityKinds(): void
    {
        $this->app->singleton(MachineIdentityKindRegistry::class, function ($app) {
            $registry = new MachineIdentityKindRegistry;

            $registry
                ->register(new MachineIdentityKind(
                    key: 'sync',
                    label: 'Sync',
                    // MEASURED, replacing the `['*']` the flagship's `RootUserSeeder` used to mint.
                    // Each line is a route or resolver that actually reads it:
                    //
                    //  - `engine:consume` is the ONLY coarse gate on the loopback's two guarded
                    //    routes (`AuthorizeEngineConsumption:28,53`, mounted at the flagship's
                    //    `routes/tenant.php:573,599`). Without it the loopback 403s.
                    //  - the `composition.*` four are the estate's own precedent for this exact
                    //    principal: `EngineConsumerToken:79` already derives precisely those from
                    //    `PermissionNamer` for the loopback token. The permission-cascade
                    //    CredentialScope intersects principal permissions with token abilities, so
                    //    a coarse-only token scopes to nothing and the composition policy denies.
                    //  - the `fragment.*` four cover the connector paths the sync daemon drives.
                    //  - `schemas.manage` covers the satellite schema path.
                    abilities: [
                        'engine:consume',
                        'composition.view',
                        'composition.create',
                        'composition.update',
                        'composition.delete',
                        'fragment.view',
                        'fragment.create',
                        'fragment.update',
                        'fragment.delete',
                        'schemas.manage',
                    ],
                    description: 'The federation sync daemon — the identity behind the estate\'s '
                        .'tenant sync pipeline. Historically seated on `tenant_users` as '
                        .'`role = \'service\'`, which is the squatting this table retires.',
                ))
                ->register(new MachineIdentityKind(
                    key: 'system',
                    label: 'System',
                    // MEASURED EMPTY — "needs nothing", NOT "unmeasured". `system@app.splicewire.com`
                    // (tower's `SystemAccountService:31`) holds zero tokens and zero roles and is
                    // never an authenticated principal; it exists only as an owner-of-record id on
                    // machine-written rows. `[]` is the correct, defensible answer here, and is
                    // distinguishable from the `null` default by `hasMeasuredAbilities()`.
                    abilities: [],
                    description: 'The platform\'s own operator identity acting inside a tenant, as '
                        .'distinct from any human operator\'s seat. Never authenticates — it is an '
                        .'owner-of-record for machine-written rows, which is why it needs nothing.',
                ));

            // The HOST half. Absent config is a normal state, not an unconfigured one: a host that
            // runs no machines of its own declares nothing and the registry holds only the two above.
            $registry->attach(new ConfigRegistrar(
                (array) ($app['config']->get('beam.tenancy.machine_identity.kinds') ?? []),
                'beam.tenancy.machine_identity.kinds',
            ));

            return $registry;
        });
    }

    /**
     * ⛔ Swap stancl's `UpdateSyncedResource` for the machine-identity-aware subclass.
     *
     * This is the single most load-bearing line of the machine-identity split. Stancl's listener
     * re-attaches a missing tenant↔user pivot mapping with NO attributes
     * (`vendor/stancl/tenancy/src/Listeners/UpdateSyncedResource.php:73-81`), so `role` takes the
     * column default `'member'`. {@see \Splicewire\Beam\Tenancy\Models\TenantUser} is `Syncable`, and
     * every machine-provisioning path ends in a `TenantUser::updateOrCreate` inside `$tenant->run()`.
     * Without this binding, the first sync after a machine row leaves `tenant_users` puts it straight
     * back as an ordinary-looking `member` seat — parseable by `Role`, indistinguishable from a human,
     * and strictly worse than the `service` it replaced, which at least announced itself.
     *
     * Bound as an interface-style container override rather than by editing any host's `$listen` map:
     * hosts list `Stancl\Tenancy\Listeners\UpdateSyncedResource::class` by class-string (the
     * flagship's `TenancyServiceProvider:117` does), and Laravel resolves a class-string listener
     * through the container. So every host gets the guard with no host edit, and no host can forget
     * it — which matters because forgetting it is silent.
     *
     * See {@see MachineIdentityAwareUpdateSyncedResource} for why it fails OPEN on a host that has
     * not published the new table.
     */
    protected function registerMachineIdentityAwareSyncListener(): void
    {
        $this->app->bind(UpdateSyncedResource::class, MachineIdentityAwareUpdateSyncedResource::class);
    }

    /**
     * The stancl adapter for beam core's {@see TenancyContext} port (ADR-0239). Plain `bind()` over
     * core's `bindIf` — the supersession lane: installing this package IS the posture declaration,
     * and the pair is order-independent. A host overrides by binding later still.
     */
    protected function registerTenancyContext(): void
    {
        $this->app->bind(TenancyContext::class, StanclTenancyContext::class);
    }

    /**
     * Default DNS + edge providers (host-binding-seam ticket 02, F3 row 6). Before this, the two
     * contracts were bound only by the flagship's provider, and an unbound host crashed
     * not-instantiable INSIDE the first provisioning job (`CreateTenantDomain` resolves both) — the
     * worst discovery location available. {@see NullDomainProvider}'s own docblock says why the
     * no-op is truthful (wildcard DNS + wildcard cert). `bindIf` in the container lane: a host with
     * real Cloudflare/Plesk drivers binds them and wins.
     */
    protected function registerDomainProviderDefaults(): void
    {
        $this->app->bindIf(TenantDnsProvider::class, NullDomainProvider::class);
        $this->app->bindIf(TenantEdgeProvider::class, NullDomainProvider::class);
    }

    /**
     * The saloon-kernel tenancy bridge (this package's ADR-0004; owner ruling 2026-10-16: the
     * mechanism lives with the tenancy package, "not just tower"). Soft edge: the contract arrives
     * via require-dev/suggest, never a hard require, so the `interface_exists` guard keeps this
     * registration free on hosts without the transport — the licenser-shaped optional-integration
     * pattern. Plain `bind()` deliberately supersedes saloon-kernel's own
     * `bindIf(NullContextBridge)`: where tenancy is installed, dropping tenant context across a
     * nested kernel dispatch is a defect, not a default. `beam.tenancy.bind_kernel_context_bridge`
     * is the escape hatch for a host that wants the Null behavior back.
     */
    protected function registerKernelContextBridge(): void
    {
        if (! interface_exists(KernelContextBridge::class)) {
            return;
        }

        if (! (bool) ($this->app['config']->get('beam.tenancy.bind_kernel_context_bridge') ?? true)) {
            return;
        }

        $this->app->bind(KernelContextBridge::class, TenancyContextBridge::class);
    }

    /**
     * The vault partition guard default (host-binding-seam ticket 02, lift C2-2). Same soft edge as
     * the kernel bridge — auth-vault is suggest-tier — but the supersession lane, not `bindIf`:
     * auth-vault's own provider `bindIf`s a `NullVaultContextGuard` and registers FIRST
     * (name-sorted package order), so a `bindIf` here would lose the race and leave every real host
     * on the no-op. Where tenancy is installed, fail-closed is the default worth having; a host
     * wanting something else binds later and wins, exactly as the flagship used to.
     */
    protected function registerVaultContextGuard(): void
    {
        if (! interface_exists(VaultContextGuard::class)) {
            return;
        }

        $this->app->bind(VaultContextGuard::class, TenancyVaultContextGuard::class);
    }

    /**
     * The stancl event map, owned by the tenancy package (this package's ADR-0003; lifted from the
     * flagship's 299-line `TenancyServiceProvider` on 2026-10-16 — the only copy in the estate, so
     * tower-starter tenants had NO pipeline subscriber and a saved tenant row proved nothing ran,
     * exactly the trap `docs/agents/traps/tenancy.md` warns about).
     *
     * The provisioning pipeline resolves from {@see TenantProvisioningStepRegistry} **at event-fire
     * time**, not at boot: the flagship read `stepsFor()` once while building its listener array, so
     * a later registration or a supersession (`OnKeyDuplicate::Supersede` — the registry's own
     * override path) was invisible to an already-wired listener. No pipeline registered → the
     * listener no-ops: this package ships NO default pipeline (see registerProvisioningSteps()), and
     * firing an empty guess would be an answer the provenance rule forbids.
     *
     * `beam.tenancy.wire_stancl_events` (default on) is the host escape hatch — a host that
     * hand-wires its own map (standwell does, with app-local step classes) sets it false instead of
     * double-dispatching.
     */
    protected function bootStanclEventMap(): void
    {
        if (! (bool) ($this->app['config']->get('beam.tenancy.wire_stancl_events') ?? true)) {
            return;
        }

        Event::listen(Events\TenantCreated::class, function (Events\TenantCreated $event): void {
            $registry = $this->app->make(TenantProvisioningStepRegistry::class);
            $pipeline = (string) $this->app['config']->get('beam.tenancy.provisioning.pipeline', 'default');

            if (! in_array($pipeline, $registry->names(), true)) {
                return;
            }

            (JobPipeline::make($registry->stepsFor($pipeline))
                ->send(fn () => $event->tenant)
                // Queued: the queue driver decides inline (sync) vs deferred (redis).
                ->shouldBeQueued(true)
                ->toListener())($event);
        });

        Event::listen(Events\TenantDeleted::class, function (Events\TenantDeleted $event): void {
            (JobPipeline::make([Jobs\DeleteDatabase::class])
                ->send(fn () => $event->tenant)
                ->shouldBeQueued(true)
                ->toListener())($event);
        });

        // Infra steps recorded in the provisioning timeline. Guarded: `markProvisioning` lives on
        // THIS package's Tenant; a host that opted out of bindTenantModel keeps stancl's model and
        // simply records nothing.
        Event::listen(Events\DatabaseCreated::class, function (Events\DatabaseCreated $event): void {
            if (method_exists($event->tenant, 'markProvisioning')) {
                $event->tenant->markProvisioning('provisioning_create_database', 'Creating database');
            }
        });
        Event::listen(Events\DatabaseMigrated::class, function (Events\DatabaseMigrated $event): void {
            if (method_exists($event->tenant, 'markProvisioning')) {
                $event->tenant->markProvisioning('provisioning_migrate_database', 'Running migrations');
            }
        });

        Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
        Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);

        // Container-resolved, so registerMachineIdentityAwareSyncListener()'s rebind applies.
        Event::listen(Events\SyncedResourceSaved::class, UpdateSyncedResource::class);
    }

    /**
     * Tenant route mounting + tenancy middleware priority, owned here with the event map (same
     * flagship lift, same `wire_host_routing` escape hatch). The routes group is file_exists-guarded:
     * a host without `routes/tenant.php` (the tower starter today) is a no-op, and adding the file IS
     * the opt-in.
     *
     * ⚠️ The `Application::booted()` callback is where the route table becomes complete — at the
     * flagship, 294 routes arrive here (api-surface-coherence 105). Beam arms per-resource discovery
     * on its own booted() callback, which fires BEFORE tenant routes exist, so the re-run is
     * triggered HERE, the one site that knows the table just grew.
     * {@see ResourceDiscoveryAutoMounter::mount()} is idempotent. Host-policy middleware on
     * discovered routes (the flagship's `auth:sanctum` on `circuits.discovery`) stays host-side, in a
     * booted() hook registered after this one.
     */
    protected function bootHostRouting(): void
    {
        if (! (bool) ($this->app['config']->get('beam.tenancy.wire_host_routing') ?? true)) {
            return;
        }

        $this->makeTenancyMiddlewareHighestPriority();

        $this->app->booted(function (): void {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::group([], base_path('routes/tenant.php'));
            }

            if ((bool) $this->app['config']->get('beam.core.discovery.enabled', true)
                && class_exists(ResourceDiscoveryAutoMounter::class)) {
                $this->app->make(ResourceDiscoveryAutoMounter::class)->mount();
            }
        });
    }

    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            // Even higher priority than the initialization middleware.
            Middleware\PreventAccessFromCentralDomains::class,

            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
            // The sole-tenant initializer (tower-is-splicewire D4′): identity from the install's state, not the request.
            InitializeTenancyBySoleTenant::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[HttpKernelContract::class]->prependToMiddlewarePriority($middleware);
        }
    }

    public function configurePackage(Package $package): void
    {
        $package
            // Beam config keys use the product word, not the `splicewire` vendor (ADR-0092;
            // precedent: laravel-beam-accounts). The source ships as a nested `config/beam/tenancy.php`
            // and is both merged and published to the same nested path in the host app, so app authors
            // see `config/beam/tenancy.php` and reach keys at `config('beam.tenancy.*')`.
            ->name('laravel-beam-tenancy')
            ->hasConfigFile(['beam/tenancy'])
            // pooled-storage ticket 05: pools are migrated once, as the owner, never per tenant.
            ->hasCommand(PoolsMigrate::class)
            ->hasCommand(PoolsRole::class)
            ->hasCommand(PoolsDirectAccess::class)
            // pooled-storage ticket 13: move a tenant between pools, on the same server or another.
            ->hasCommand(PoolsMove::class)
            // Publish-only .stub migrations (NOT ->discoversMigrations(), which loads at runtime).
            // Declared order matters: `create_tenants_table` must sort ahead of the ALTERs below it,
            // and package-tools' generateMigrationName timestamps them in listed order.
            ->hasMigrations([
                'create_tenants_table',
                'create_domains_table',
                'create_tenant_users_table',
                // The MACHINE half of "who may enter this tenant" — listed after `create_tenants_table`
                // and the central `users` substrate it FKs, exactly like `create_tenant_users_table`
                // beside it. See the stub's own docblock for why it carries no `role` and no
                // `accepted_at`, and why that absence is what keeps machines out of per-seat billing.
                'create_tenant_machine_identities_table',
            ]);
    }

    /**
     * Seat `tenancy.tenant_model` on THIS package's Tenant unless the host has chosen its own.
     *
     * ⚠️ The package publishes a `tenants` schema only its own model can key — NOT NULL `name`,
     * unique `slug`, `parent_tenant_id`, all listed in {@see Tenant::getCustomColumns()}. Stancl's
     * model lists `['id']`, so its `HasDataColumn` trait routes every other attribute into the
     * `data` JSON and the NOT NULL column is never written. Shipping the schema without the model
     * that can key it is the same pairing defect beam-accounts hit with `permission.models.role`.
     *
     * Guarded THREE ways, because a package writing into another package's config namespace has to
     * be conservative:
     *
     *  - `beam.tenancy.bind_tenant_model` (default true) lets a host opt out wholesale.
     *  - It only replaces stancl's OWN default. Any other value means the host chose, and a host
     *    choice always wins — the same last-writer rule as the morph alias above.
     *  - It runs in `packageBooted()`, after every provider has registered and after a published
     *    `config/tenancy.php` has been read, so "the host chose" is actually knowable.
     *
     * Measured 2026-08-30: of the three `~/Herd` hosts installing beam-tenancy, only `splicewire-app`
     * worked, and only because someone had hand-written this binding. `tower` was live-broken;
     * `satellite` was latent. The package suite could not see it — `InteractsWithTenancy` pins the
     * model for tests, so the harness booted what no host did.
     */
    protected function bindTenantModel(): void
    {
        if (! config('beam.tenancy.bind_tenant_model', true)) {
            return;
        }

        // Only when the host has expressed no preference — i.e. the value is still the one stancl
        // merges from its own package config.
        if (config('tenancy.tenant_model') !== StanclTenant::class) {
            return;
        }

        config(['tenancy.tenant_model' => Tenant::class]);
    }

    /**
     * Re-bind the sitemap base-URL port to the multi-domain resolver (ADR-0166 §3). beam-sitemap binds
     * the config-default in its own `register()`; we override it here in `packageBooted()` (after all
     * providers have registered) with a resolver that returns the active tenant's domain, falling back
     * to whatever base-URL resolver was previously bound — the config-default in a normal install —
     * when tenancy is absent.
     *
     * Guarded on the port interface existing, so a host that installs beam-tenancy without the
     * beam-sitemap arm never trips this. Cross-tenant aggregation is deferred to tower (ADR-0166 §5) —
     * not bound here.
     */
    public function packageBooted(): void
    {
        $this->bindTenantModel();
        $this->bootStanclEventMap();
        $this->bootHostRouting();

        // The `tenant` morph alias — the wire identifier every polymorphic row pointing at a Tenant
        // stores (billable, subscriptions, bills, grants …), and the permission-token prefix
        // (ADR-0118). The package that OWNS the model owns its alias; a host should only have to
        // declare aliases for its OWN models, and every beam-composing host needs this one.
        //
        // ADDITIVE (`Relation::morphMap`), NEVER `enforceMorphMap`: global strict mode rejects every
        // class-string morph the host still has. Mirrors {@see \Splicewire\Beam\BeamServiceProvider}.
        // A host booting later keeps last-writer override authority, so a host that substitutes its
        // own Tenant model can still repoint the alias.
        // The `tenant_user` alias, on exactly the same reasoning one paragraph up, for the model that
        // HOLDS every tenant-scoped role grant. `TenantUser` uses spatie's `HasRoles`, whose `roles()`
        // is a plain `morphToMany` — so Laravel writes `$parent->getMorphClass()` into
        // `model_has_roles.model_type` and constrains every read against the same value
        // (`MorphToMany:71` sets `$this->morphClass` once; lines 88/98/111/119/146/160 all use it).
        // With no alias declared that value was the fully-qualified class name, which is precisely the
        // durable class-name-in-a-column ADR-0118 exists to prevent.
        //
        // Measured at ~/Herd/splicewire-app 2026-09-01, `public.model_has_roles`: 35 rows holding the
        // FQCN against 2 holding `user` — one column, two vocabularies, because `App\Models\User` was
        // aliased by the host and this model was not. ADR-0217 decision 5 ("registration follows
        // ownership") puts the alias here, in the package that owns the model, not at the host.
        //
        // The key is the snake_case SHORT NAME of the class, per house rule — never the table, which
        // is `users` and already spoken for. `user` is likewise already bound to the central
        // `App\Models\User`, and the two models are distinct role holders that can share a uuid (they
        // are `Syncable` counterparts), so a DISTINCT key is required: the pivot's unique constraint
        // is `(team_id, role_id, model_id, model_type)` and collapsing both onto `user` would collide.
        // `tenant_user` is free in the booted 113-entry morph map and among the host's 54 particle
        // resource keys.
        //
        // ⚠️ This declaration and the data migration that rewrites the existing rows are ONE change.
        // Because spatie constrains reads with the same token it writes, registering the alias without
        // converting the rows makes every pre-existing grant invisible and 403s every policy-gated
        // surface for those holders. See the app's
        // `database/migrations/2026_09_01_000400_store_morph_aliases_in_permission_tables.php`.
        Relation::morphMap([
            'tenant' => Tenant::class,
            'tenant_user' => Models\TenantUser::class,
        ]);

        $this->registerSharedMigrationsPath();
        $this->registerTenancyTerminator();
        $this->bootFrameResources();

        // Join beam-core's bypass/redundancy/house-style audit sweeps: contribute this package's HTTP
        // surface to the boot-time AuditScanPaths seam. Guarded on the singleton being bound (a host
        // predating the seam still boots). The sweep only ever sees what a booted provider contributes,
        // so until now TenantMemberController — a REST survivor of the ADR-0156 fold, sitting next to a
        // registered `members` resource — has been structurally invisible to
        // `particle.controller-redundant`. Not clean; unexamined.
        //
        // The routes dir does NOT exist here and that is correct, not an oversight: this package
        // declares controllers and the consuming host mounts them (splicewire-app's routes/tenant.php),
        // where beam-commerce ships its own route files. `register()` documents an absent dir as fine —
        // the audits' file walks treat it as empty — so the pair stays honest rather than pointing at
        // some other package's routes.
        if ($this->app->bound(AuditScanPaths::class)) {
            $this->app->make(AuditScanPaths::class)->registerScanPaths(
                'splicewire/laravel-beam-tenancy',
                __DIR__.'/Http',
                dirname(__DIR__).'/routes',
            );
        }

        // Self-register into beam-core's install manifest (order 5: tenants/domains/users are
        // foundational — publish early, ahead of the default-order-100 packages that FK into them)
        // so `splicewire:beam:install` publishes this package's central + config with the rest of
        // the stack. Recohere gap: this package predates the manifest and was never wired in.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-tenancy',
                publishTags: ['beam-tenancy-config', 'beam-tenancy-migrations'],
                migrates: true,
                order: 5,
            );
        }

        // beam-tenancy is itself an "operator" of the estate-wide publish-only stub migrations
        // convention — self-registers the doctor/operator check on ITS OWN migrations, same as every
        // other beam-* package registers it on theirs.
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-tenancy',
                BeamTenancyMigrationsAudit::class,
            );

            // ADVISORY by construction — the audit itself can only ever emit Pass or Warn, never
            // Fail, and `gate` stays false so it cannot hold a doctor run's floor. "Is there a
            // machine-shaped row in this host's data" is the textbook host-dependent question, and a
            // host carrying those rows is a host awaiting a data migration, not a broken one. See
            // {@see MachineIdentityOnMembershipPivotAudit} for the estate precedent this follows.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-tenancy',
                MachineIdentityOnMembershipPivotAudit::class,
            );

            // GATES (pooled-storage ticket 06): a pooled tenant whose session-setting marker names
            // another tenant's key reads that tenant's rows on every request with a 200. Inconclusive
            // over zero pooled tenants, so a host with none is not held by it.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-tenancy',
                PooledTenantMarkersAudit::class,
                gate: true,
            );

            // GATES: coverage, role and owner exposure from rushing/laravel-postgres-rls over every pool,
            // through a probe connection per pool (see the wrapper's docblock). Inconclusive over zero
            // pooled tenants.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-tenancy',
                PooledStorageAudit::class,
                gate: true,
            );

            // ADVISORY: the frame audit (is the setting live on the probe?) — a second registration,
            // because a Warn inside a gate registration would fail a `--floor=warn` run.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-tenancy',
                PooledStorageFrameAudit::class,
            );
        }

        $this->bootSeed();

        if (! interface_exists(SitemapBaseUrlResolver::class)) {
            return;
        }

        $this->app->singleton(SitemapBaseUrlResolver::class, function ($app) {
            // The fallback used when tenancy is absent: the config-default. Constructed
            // directly (not re-resolved off the container, which we are overriding) so
            // this binding can never recurse into itself.
            $fallback = $app->make(ConfigSitemapBaseUrlResolver::class);

            return new TenantSitemapBaseUrlResolver($app, $fallback);
        });
    }

    /**
     * The HOST-side half of the estate's "everything is shared by default" convention: a ubiquitous
     * (central + every tenant) table is published by ANY beam-* package into a SINGLE destination,
     * `database/migrations/shared/` (via that package's own `->hasMigrations(['shared/...'])` —
     * never `loadMigrationsFrom()` on its own vendor source; every package still ALWAYS publishes).
     * Something still has to actually RUN that directory in both migration passes — beam-tenancy, as
     * the authoritative tenancy-substrate package, establishes that here.
     *
     * This is NOT a package auto-loading its own un-published migrations (the convention that stays
     * forbidden — {@see BeamTenancyMigrationsAudit} still fails a package that does that). It is
     * host-side glue over an ALREADY-published directory: registering a custom migrations path is
     * ordinary Laravel app wiring, no different from any app declaring a second `database/migrations/`
     * subdirectory. Laravel's own `migrate` does not recurse into subdirectories by default, so the
     * central registration is the explicit opt-in; Stancl's `tenants:migrate` only ever runs the
     * directories named in `tenancy.migration_parameters.--path`, so the tenant registration pushes
     * ours on (guarded so a host that boots this provider twice — the shared-test-DB harness — does
     * not duplicate the entry). Safe unconditionally on a single-tenant host: `loadMigrationsFrom` on
     * an empty/missing directory is a no-op, and pushing onto an unread config key is harmless.
     */
    /**
     * Register this package's Frame/particle resources — the neutral `tenants` list, scanned off its
     * attribute.
     *
     * ⚠️ It used to also register an imperative `tenant-invitations` declaration, and that key existed
     * for exactly one reason: `splicewire/laravel-beam-accounts` already owned `invitations` against a
     * DIFFERENT model on a different table, the registry is last-wins by key, and an operation's subject
     * must not be a fact about provider order. There is one invitation model now
     * ({@see \Splicewire\Beam\Accounts\Models\Invitation}, whose `team_id` holds a `TeamContract` key
     * rather than a `beam_teams` row), so the key is not contested and the second declaration has
     * nothing left to disambiguate. The operations that resolved through it declare `invitations`.
     *
     * ## Registers UNCONDITIONALLY, and the direct call is the point.
     *
     * Two prior shapes are retired here, both recorded because both looked correct.
     *
     * 1. This wrapped its registration in `$this->app->afterResolving(…)`, on the reasoning that the hook
     *    made boot order between beam and beam-tenancy irrelevant. Wrong in both halves: the hook NEVER
     *    FIRED (beam resolves the registry in its own `packageBooted()`, and Laravel returns a cached
     *    singleton without running resolving callbacks), and it was never needed — beam BINDS the registry
     *    in the REGISTER phase, so `bound()` is already true here whatever the provider order, and the
     *    direct `make()->register()` below is order-safe by construction. `afterResolving` on a particle
     *    registry now throws (`DeadResolvingHookGuard`). Particle-contribution-seam ticket 07.
     *
     * 2. Ticket 07 then deliberately left the body EMPTY, because repairing the registration under the
     *    old contract made things worse: the registry keyed by resource key and the last write won, so a
     *    9-prop OOTB `tenants` registered at provider position 13 was wholesale-replaced by tower's
     *    22-prop declaration at position 19 — manufacturing, for real, the god-projection the map exists
     *    to retire. That reasoning died with the contract. Ticket 04 §A1 ruled that the CONTRIBUTOR
     *    declares its own slice and the owner is never overwritten; ticket 15 deletes tower's competing
     *    declaration. So the hold-back has nothing left to protect, and the registration is restored
     *    (ticket 14) together with the widening that makes it worth registering.
     *
     * The `class_exists`/`bound` guard stays, and is structural rather than policy: a host that installs
     * beam-tenancy without beam's particle registry has no registry to register into, and should get
     * nothing rather than a fatal.
     */
    protected function bootFrameResources(): void
    {
        if (
            ! class_exists(ParticleResourceRegistry::class)
            || ! class_exists(AttributedParticleDiscovery::class)
            || ! $this->app->bound(ParticleResourceRegistry::class)
        ) {
            return;
        }

        // This package's own declaration root, scanned rather than named. `TenantData` is the only
        // declaration under it today; a second one registers by existing as a file, which is the only
        // version of this that cannot rot. Idempotent by key — a host that also lists the class in
        // `beam.core.particle.classes` gets the same registration twice and the same result.
        $this->app->make(AttributedParticleDiscovery::class)
            ->discover(paths: [__DIR__.'/Data']);
    }

    /**
     * Register {@see DemoTenantSeeder} into beam-core's seed manifest, so `splicewire:beam:seed`
     * seats the demo roster on a tenant as part of the one stack-wide seed run.
     *
     * Mirrors beam-accounts' `WiresSeed` in all three of its load-bearing properties:
     *
     * - **Inert without the manifest.** A host that composes beam-tenancy without beam's seed
     *   command has nothing to register into and pays nothing.
     * - **Gated, non-production by default.** `beam.tenancy.demo.seed_tenant` defaults to null,
     *   and a null resolves HERE to `! production` — the manifest's own check is a raw
     *   `config($gate)` and cannot run environment logic, so the resolved boolean is written back
     *   onto the same key. Explicit config always wins.
     * - **Ordered after beam-accounts.** `order: 20` against beam-accounts' `10`, so the demo
     *   users exist before this seeder seats them. (The seeder `firstOrCreate`s them anyway, so
     *   ordering is an optimisation, not a dependency.)
     */
    protected function bootSeed(): void
    {
        if (! class_exists(BeamSeedManifest::class) || ! $this->app->bound(BeamSeedManifest::class)) {
            return;
        }

        $flag = config('beam.tenancy.demo.seed_tenant');
        $enabled = $flag !== null ? (bool) $flag : ! $this->app->environment('production');
        config(['beam.tenancy.demo.seed_tenant' => $enabled]);

        $this->app->make(BeamSeedManifest::class)->register(
            package: 'splicewire/laravel-beam-tenancy',
            seederClass: DemoTenantSeeder::class,
            order: 20,
            configGate: 'beam.tenancy.demo.seed_tenant',
        );
    }

    protected function registerSharedMigrationsPath(): void
    {
        $sharedDir = database_path('migrations/shared');

        $this->loadMigrationsFrom($sharedDir);

        $paths = config('tenancy.migration_parameters.--path', []);

        if (! in_array($sharedDir, $paths, true)) {
            config()->push('tenancy.migration_parameters.--path', $sharedDir);
        }
    }

    /**
     * Push {@see Http\Middleware\EndTenancyOnTerminate} onto the global HTTP middleware stack, so a
     * request that bootstrapped tenancy leaves the connection in central context when it terminates.
     *
     * ⚠️ This is a **correctness** requirement under Octane/FrankenPHP, not hygiene. A worker's
     * connection outlives the request, and `PostgreSQLSchemaManager` sets `search_path = "$tenant,public"`
     * — so a leaked frame resolves silently against the wrong tenant's rows instead of erroring. See the
     * middleware's own docblock for the full reasoning and the measurement behind it.
     *
     * **Global, not a route group.** A tenant is identified by middleware that any route can decline to
     * use; the *reset* must not be declinable the same way, or the one route that forgets it poisons
     * every request that follows it in that worker.
     *
     * **Pushed, not prepended.** Laravel terminates middleware in stack order, so appending makes this
     * the last `terminate()` to run — anything else terminating on the same request still sees tenancy.
     *
     * Guarded three ways, because a package provider boots in contexts an app kernel does not exist in:
     * console-only harnesses, and hosts that deliberately manage the lifecycle themselves
     * (`beam.tenancy.end_on_terminate => false`).
     */
    protected function registerTenancyTerminator(): void
    {
        if (! config('beam.tenancy.end_on_terminate', true)) {
            return;
        }

        if (! $this->app->bound(HttpKernelContract::class)) {
            return;
        }

        $kernel = $this->app->make(HttpKernelContract::class);

        // `pushMiddleware` is itself idempotent (it checks `array_search` before appending), so a
        // double-boot cannot register this twice.
        if ($kernel instanceof HttpKernel) {
            $kernel->pushMiddleware(Http\Middleware\EndTenancyOnTerminate::class);
        }
    }
}
