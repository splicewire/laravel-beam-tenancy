<?php

namespace Splicewire\Beam\Tenancy;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Sitemap\Resolvers\ConfigSitemapBaseUrlResolver;
use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Tenancy\Doctor\BeamTenancyMigrationsAudit;
use Splicewire\Beam\Tenancy\Sitemap\TenantSitemapBaseUrlResolver;

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
class BeamMultiTenancyServiceProvider extends PackageServiceProvider
{
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
            ]);
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
        $this->registerSharedMigrationsPath();

        // beam-tenancy is itself an "operator" of the estate-wide publish-only stub migrations
        // convention — self-registers the doctor/operator check on ITS OWN migrations, same as every
        // other beam-* package registers it on theirs.
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-tenancy',
                BeamTenancyMigrationsAudit::class,
            );
        }

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
