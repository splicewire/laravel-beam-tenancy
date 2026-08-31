<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Database\Eloquent\Relations\Relation;
use Rushing\Popcorn\Laravel\Runner\NullRunner;
use Rushing\Popcorn\Registries\Registrars\ConfigRegistrar;
use Rushing\Popcorn\Registries\RegistryIndex;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;
use Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
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
use Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder;
use Splicewire\Beam\Tenancy\Destinations\CustomerSuppliedDatabaseDestination;
use Splicewire\Beam\Tenancy\Destinations\GcpCloudSqlDestination;
use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseDestination;
use Splicewire\Beam\Tenancy\Doctor\BeamTenancyMigrationsAudit;
use Splicewire\Beam\Tenancy\Doctor\MachineIdentityOnMembershipPivotAudit;
use Splicewire\Beam\Tenancy\Listeners\MachineIdentityAwareUpdateSyncedResource;
use Splicewire\Beam\Tenancy\MachineIdentity\MachineIdentityKind;
use Splicewire\Beam\Tenancy\MachineIdentity\MachineIdentityKindRegistry;
use Splicewire\Beam\Tenancy\Sitemap\TenantSitemapBaseUrlResolver;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;

/**
 * The PURE-tenancy substrate the whole estate FKs to: `tenants` (stancl core) + `domains`,
 * `tenant_users`, `tenant_invitations`. Each create is squashed pre-prod (no deployed data to
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
    public function register(): void
    {
        parent::register();

        $this->registerMachineIdentityKinds();
        $this->registerMachineIdentityAwareSyncListener();

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
     * `OnDuplicate::Supersede` means a later hand-registration wins — so a host that wants to
     * override `sync` declares it in config or registers it later, and does not have to fight this
     * package for the key.
     *
     * ⚠️ Both shipped kinds declare an EMPTY `abilities` list. That is the sibling pass's slot, not
     * an oversight and not a deny-all — see {@see MachineIdentityKind}. Do not fill it with invented
     * ability strings to make it look finished.
     */
    protected function registerMachineIdentityKinds(): void
    {
        $this->app->singleton(MachineIdentityKindRegistry::class, function ($app) {
            $registry = new MachineIdentityKindRegistry;

            $registry
                ->register(new MachineIdentityKind(
                    key: 'sync',
                    label: 'Sync',
                    abilities: [],
                    description: 'The federation sync daemon — the identity behind the estate\'s '
                        .'tenant sync pipeline. Historically seated on `tenant_users` as '
                        .'`role = \'service\'`, which is the squatting this table retires.',
                ))
                ->register(new MachineIdentityKind(
                    key: 'system',
                    label: 'System',
                    abilities: [],
                    description: 'The platform\'s own operator identity acting inside a tenant, as '
                        .'distinct from any human operator\'s seat.',
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

    public function configurePackage(Package $package): void
    {
        $package
            // Beam config keys use the product word, not the `splicewire` vendor (ADR-0092;
            // precedent: laravel-beam-accounts). The source ships as a nested `config/beam/tenancy.php`
            // and is both merged and published to the same nested path in the host app, so app authors
            // see `config/beam/tenancy.php` and reach keys at `config('beam.tenancy.*')`.
            ->name('laravel-beam-tenancy')
            ->hasConfigFile(['beam/tenancy'])
            // Publish-only .stub migrations (NOT ->discoversMigrations(), which loads at runtime).
            // Declared order matters: `create_tenants_table` must sort ahead of the ALTERs below it,
            // and package-tools' generateMigrationName timestamps them in listed order.
            ->hasMigrations([
                'create_tenants_table',
                'create_domains_table',
                'create_tenant_users_table',
                'create_tenant_invitations_table',
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

        // The `tenant` morph alias — the wire identifier every polymorphic row pointing at a Tenant
        // stores (billable, subscriptions, bills, grants …), and the permission-token prefix
        // (ADR-0118). The package that OWNS the model owns its alias; a host should only have to
        // declare aliases for its OWN models, and every beam-composing host needs this one.
        //
        // ADDITIVE (`Relation::morphMap`), NEVER `enforceMorphMap`: global strict mode rejects every
        // class-string morph the host still has. Mirrors {@see \Splicewire\Beam\BeamServiceProvider}.
        // A host booting later keeps last-writer override authority, so a host that substitutes its
        // own Tenant model can still repoint the alias.
        Relation::morphMap(['tenant' => Tenant::class]);

        $this->registerSharedMigrationsPath();
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
        }

        // Declaring and indexing are two acts (registry-kernel 21 D1). MachineIdentityKindRegistry
        // DECLARES `beam.tenancy.machine-identity.kinds` via its attribute; this is where that root
        // becomes routable. Guarded on the index being bound so a host composing beam-tenancy without
        // laravel-popcorn's provider still boots.
        if ($this->app->bound(RegistryIndex::class)) {
            $this->app->make(RegistryIndex::class)->describe(
                $this->app->make(MachineIdentityKindRegistry::class),
                by: self::class,
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
     * Register this package's Frame/particle resources — today just the neutral `tenants` list.
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
}
