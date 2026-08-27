<?php

return [
    'system_tenant' => [
        'slug' => env('SPLICEWIRE_SYSTEM_TENANT_SLUG', 'system'),
        'name' => 'System',
        'role' => 'system',
    ],

    /*
     * The demo TENANT — the tenancy-side counterpart to beam-accounts' demo TEAM.
     *
     * beam-accounts seats its role-differentiated demo roster (demo-owner / demo-admin /
     * demo-member) on a beam-accounts `Team`. Nothing seats them on a *tenant*, so an estate
     * with a full demo roster still had zero role-differentiated `tenant_users` seats — every
     * seat belonged to a root account, and no test could obtain "an ordinary tenant member
     * holding no central permissions". `DemoTenantSeeder` closes that: it reaches DOWN into
     * beam-accounts' roster (beam-tenancy requires beam-accounts, never the reverse) and seats
     * it on a tenant of its own.
     *
     * `seed_tenant` is the seed-manifest gate. Null (the default) resolves at boot to
     * `! production`, mirroring `beam.accounts.demo.seed_users` — dev and preview seed by
     * default, production never fabricates demo data. An explicit value always wins.
     *
     * The tenant is DEDICATED (its own id/slug), not an existing one. Two reasons, both
     * measured: an existing tenant may already carry seats whose `role` is outside the
     * `Role` enum (`service` rows exist in the wild, and `memberRole()` raises a `ValueError`
     * on them), and hosts keep some tenants' role grants in a per-tenant `model_has_roles`
     * that nothing reads. A tenant this seeder owns end-to-end has neither problem.
     */
    'demo' => [
        'seed_tenant' => env('BEAM_TENANCY_SEED_DEMO_TENANT'),

        'tenant' => [
            // NOT `demo` — that slug is already taken by hand-made tenants in the estate, and
            // the point of this one is that the seeder owns every seat on it.
            //
            // UNDERSCORE, not a hyphen. The tenant id is not just an id: stancl derives the
            // tenant's storage name from it (`tenancy.database.prefix` . id), so this slug
            // becomes a Postgres SCHEMA name — and it is spliced into a `search_path`, a
            // Redis `prefix_base`, and a search-index name besides. `tenant_beam-demo` is not
            // a legal bare Postgres identifier and survives only as long as every consumer
            // remembers to quote it; `tenant_beam_demo` needs nobody to remember anything, and
            // matches every id already in the estate (`system`, `demo`, `entreport`), all of
            // which are single bare words. {@see DemoTenantSeeder::slug()} rejects anything
            // outside `[a-z0-9_]` rather than let a hyphen back in through env.
            'slug' => env('BEAM_TENANCY_DEMO_TENANT_SLUG', 'beam_demo'),
            'name' => 'Beam Demo',

            /*
             * Whether the seeder PROVISIONS the tenant's storage (create the schema, migrate it)
             * rather than leaving a central row pointing at a database that does not exist.
             *
             * On by default, and it is the fix for a real defect: the first cut of this seeder
             * created the tenant row and stopped. At the flagship the host's own `TenantCreated`
             * pipeline is `shouldBeQueued(true)` against a redis queue with no worker running, so
             * nothing provisioned it — the row landed with no `tenancy_db_name` and no schema, and
             * `$tenant->run(...)` threw `Database tenant_beam-demo does not exist.` A tenant that
             * throws on connect is a landmine for every `Tenant::all()` sweep in the estate: the
             * loop ABORTS on it, and the partial list it leaves behind reads like a complete one.
             *
             * Set false only on a host that provisions demo tenants some other way and wants the
             * seeder to stop at central seats. It is not a way to opt out of the storage — it is a
             * statement that something else owns it.
             */
            'provision' => env('BEAM_TENANCY_DEMO_TENANT_PROVISION', true),
        ],
    ],

    /*
     * The package's CENTRAL tenancy substrate migrations
     * (tenants/domains/tenant_users/tenant_invitations + tenant-row ALTERs) are
     * PUBLISH-ONLY .stub files, registered via spatie/laravel-package-tools'
     * `->hasMigrations([...])` under the `beam-tenancy-migrations` tag
     * (`vendor:publish --tag=beam-tenancy-migrations`), which re-stamps them to the
     * install moment; the host's `migrate` pass then runs the copies. No
     * `register_migrations` knob — a host that owns the substrate itself simply
     * doesn't publish the tag.
     */

    /*
     * The model `Tenant::billingAccount()` resolves its polymorphic `beam_billable` row from —
     * in practice `laravel-beam-commerce`'s billing-account model, which this package cannot
     * name. beam-commerce REQUIRES beam-tenancy, so a tenancy-side reference to a commerce class
     * closes a dependency cycle; the seam is how the relation reaches a model it must never
     * declare. Mirrors `beam.accounts.tokens.model`.
     *
     * Null (the default) is a real, supported state, not an unconfigured one: multi-tenancy is
     * optional to billing and billing is optional to multi-tenancy, so a host with no billing
     * engine leaves this unbound and the relation degrades to no billing account. It never runs
     * SQL in that state — see NullBillingAccount — because such a host has no `beam_billable`
     * table to query. A class name that isn't installed degrades the same way.
     */
    'billing_account_model' => null,

    /*
     * The contract a tenant model-catalog entry's `provider` must implement to be offered as a
     * usable model (see `Tenant::assertUsableModelEntry()`). Owned upward — today tower's
     * `ProvidesChatCompletions` — so it is named by STRING here rather than imported: a default
     * value is not a dependency, and beam-tenancy still requires nothing from tower.
     *
     * This is a seam because the FQN moves. It was previously hardcoded to
     * `App\Contracts\ProvidesChatCompletions`; when the contract moved into tower, `class_exists()`
     * went false and the guard rejected EVERY catalog entry instead of validating them. A config key
     * makes the next relocation a one-line edit in a host that has already moved.
     *
     * When the named interface isn't installed at all, the guard skips the interface check (there is
     * nothing to check against) but still requires `provider` to name a class that exists.
     */
    'model_provider_contract' => 'Splicewire\\Tower\\Contracts\\ProvidesChatCompletions',

    /*
     * The Isolated Database provisioning destination (tenant-database-upsell ticket 02/04):
     * a dedicated Laravel Cloud Serverless Postgres cluster per tenant, used purely as an
     * external managed-Postgres vendor. The API token is org-scoped, not app-specific — it's
     * shared with `rushing/splicewire-beam-runbook`'s laravel-cloud-provisioning app-deploy
     * pilot (ticket 05's decision; revisit splitting into a dedicated token before production).
     */
    'isolated_database' => [
        'cloud_binary' => env('LARAVEL_CLOUD_CLI_BIN', 'cloud'),
        // Live migration's data-copy step (ticket 04) shells out to these directly — not on
        // PATH on every box (this dev machine only ships `psql` on PATH via Herd).
        'pg_dump_bin' => env('PG_DUMP_BIN', 'pg_dump'),
        'psql_bin' => env('PSQL_BIN', 'psql'),
        'api_token' => env('LARAVEL_CLOUD_API_TOKEN'),
        'region' => env('LARAVEL_CLOUD_DB_REGION', 'us-east-1'),
        'cluster_type' => env('LARAVEL_CLOUD_DB_TYPE', 'neon_serverless_postgres_18'),
        // Extensions today's schema tenants borrow from `public` via search_path (ticket 01's
        // finding) that a fresh isolated database needs installed directly.
        'extensions' => ['vector', 'fuzzystrmatch'],
        // A dedicated HOME so the CLI's own config-file auth (its only auth path) never
        // touches a real developer's `~/.config/cloud/config.json`.
        'cli_home' => storage_path('app/laravel-cloud-cli'),
    ],

    /*
     * The replacement managed provisioning destination (tenant-database-upsell ticket 16):
     * Laravel Cloud is retired for new provisioning (`isolated_database` above stays frozen,
     * entreport only) — a public-IP, Authorized-Networks-allowlisted GCP Cloud SQL instance
     * is the new default. Driven through laravel-beam-provision's real TofuApplyDispatcher
     * apply pipeline, with a keyless Workload Identity Federation credential (no static GCP
     * key anywhere — see GcpCloudSqlDestination's own docblock).
     */
    'gcp_cloud_sql' => [
        'project' => env('GCP_PROJECT', 'splicewire'),
        'region' => env('GCP_REGION', 'us-central1'),

        // The SA GcpCloudSqlDestination impersonates via Workload Identity Federation — must
        // grant the WIF principal `roles/iam.serviceAccountTokenCreator` (ticket 16 task 5).
        // Reuses the SAME `beam-provision` SA the sibling CloudRunDestination already
        // impersonates (gcp-cloud-run-provisioning ticket 02) — one identity, least-privilege
        // roles for both consumers, not a second SA to provision and track.
        'service_account_email' => env('GCP_BEAM_PROVISION_SERVICE_ACCOUNT', 'beam-provision@splicewire.iam.gserviceaccount.com'),

        // The full Workload Identity Federation provider resource name
        // (//iam.googleapis.com/projects/{num}/locations/global/workloadIdentityPools/{pool}/providers/{provider}) —
        // null until ticket 16 tasks 3/4 actually provision the pool (blocked on splicewire-app
        // going live on a real public domain the provider's issuer check can reach).
        'workload_identity_provider' => env('GCP_WORKLOAD_IDENTITY_PROVIDER'),

        // Cloud SQL's Authorized Networks IP allowlist — the network-boundary replacement for
        // private-IP isolation (ticket 09's flagged gap: Laravel Cloud had no IP-allowlist
        // option at any tier; this is a strictly stronger posture). Empty by default —
        // reachable from nowhere until an entry is added, never open-by-default. JSON-encoded
        // list of {name, cidr} via env, e.g. '[{"name":"plesk2","cidr":"203.0.113.5/32"}]'.
        'authorized_networks' => json_decode((string) env('GCP_CLOUD_SQL_AUTHORIZED_NETWORKS', '[]'), true) ?: [],

        // Same extension list every destination installs (ticket 01's finding) — kept
        // independently configurable per destination rather than shared with
        // `isolated_database` above, matching that block's own precedent.
        'extensions' => ['vector', 'fuzzystrmatch'],
    ],
];
