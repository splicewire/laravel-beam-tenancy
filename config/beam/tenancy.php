<?php

return [
    'system_tenant' => [
        'slug' => env('SPLICEWIRE_SYSTEM_TENANT_SLUG', 'system'),
        'name' => 'System',
        'role' => 'system',
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
     * The packaged Frame/particle resources — today the neutral `tenants` list. On by default, so a
     * fresh multi-tenant host can see what tenants exist without installing commerce or writing a
     * resource. Turn it OFF in a deployment whose own richer resource owns the `tenants` key, so the
     * two never contest it: splicewire-app does exactly that for tower's enriched variant, the same
     * way it already turns off `beam.accounts.frame_resources.enabled`.
     */
    'frame_resources' => [
        'enabled' => true,
    ],

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
];
