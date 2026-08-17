<?php

namespace Splicewire\Beam\Tenancy;

use Splicewire\Beam\Tenancy\Destinations\CustomerSuppliedDatabaseDestination;
use Splicewire\Beam\Tenancy\Destinations\GcpCloudSqlDestination;
use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseDestination;
use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseTrustStore;
use Splicewire\Beam\Tenancy\Destinations\ProvisioningDestination;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * The provisioning-destination seam's real center (tenant-database-upsell ticket 02):
 * stancl resolves exactly one manager class per Postgres driver string app-wide, with no
 * native mixed-driver support — this branches per-`$tenant` between the existing
 * {@see PostgreSQLSchemaManager} behavior (the default, shared-cluster schema-per-tenant)
 * and Isolated Database behavior backed by whichever {@see ProvisioningDestination} the
 * tenant's `isolated_database_destination` marker names (ticket 13) — load-bearing for
 * {@see deleteDatabase()}, which must never call Laravel Cloud's cluster-deleting teardown
 * against a customer-supplied tenant it doesn't own the lifecycle of. No global state: every
 * interface method already receives (or, for `databaseExists`, resolves) the tenant, so
 * branching is a per-call concern.
 *
 * Registered as the single `pgsql` entry in `config/tenancy.php`, replacing the bare
 * `PostgreSQLSchemaManager` registration.
 *
 * Three-way as of tenant-database-upsell ticket 16: `laravel_cloud` (frozen — entreport only,
 * retired for new provisioning), `gcp_cloud_sql` (the new managed default), and
 * `customer_supplied` (unchanged). `$laravelCloud` stays a constructor dependency purely for
 * {@see destinationFor()}'s teardown routing on tenants already marked `laravel_cloud` — no
 * code path here provisions a NEW tenant onto it anymore.
 */
class HybridPostgresTenantDatabaseManager implements TenantDatabaseManager
{
    protected ?string $connection = null;

    public function __construct(
        protected PostgreSQLSchemaManager $schemaManager,
        protected IsolatedDatabaseDestination $laravelCloud,
        protected CustomerSuppliedDatabaseDestination $customerSupplied,
        protected GcpCloudSqlDestination $gcpCloudSql,
    ) {}

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
        $this->schemaManager->setConnection($connection);
    }

    /**
     * Net-new isolated-at-creation-time provisioning. `gcp_cloud_sql` only, as of ticket 16 —
     * `laravel_cloud` is retired for new provisioning fleet-wide (see this class's own
     * docblock); `isolatedDatabaseDestination()`'s `'laravel_cloud'` fallback exists for
     * ALREADY-provisioned tenants that predate the marker, not as a live creation-time choice,
     * so it is deliberately never consulted here. `customer_supplied` stays unsupported at
     * creation time (ticket 08 shipped no creation-time destination picker; that tenant only
     * ever arrives via the live-migration job, which calls its destination directly, bypassing
     * this manager entirely — see `MigrateTenantToIsolatedDatabase`'s own docblock). Reaching
     * this branch for a `customer_supplied` tenant would mean that assumption broke; fail loud
     * rather than guess at unstaged connection params.
     */
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        if (! $this->isolated($tenant)) {
            return $this->schemaManager->createDatabase($tenant);
        }

        /** @var Tenant $tenant */
        if ($tenant->isolatedDatabaseDestination() === 'customer_supplied') {
            throw new \RuntimeException(
                "Tenant '{$tenant->getTenantKey()}' requests a customer-supplied Isolated Database at creation time — unsupported. Customer-supplied destinations are only reachable via the live-migration upgrade path."
            );
        }

        $result = $this->gcpCloudSql->provision(['name' => $this->clusterName($tenant)]);
        $this->gcpCloudSql->installExtensions($result['connection'], $result['database']);

        $tenant->isolated_database_cluster_id = $result['identifier'];
        $tenant->markIsolatedDatabaseDestination('gcp_cloud_sql');
        $tenant->setInternal('db_host', $result['connection']['hostname']);
        $tenant->setInternal('db_port', (string) $result['connection']['port']);
        $tenant->setInternal('db_username', $result['connection']['username']);
        $tenant->setInternal('db_password', $result['connection']['password']);
        $tenant->setInternal('db_name', $result['database']);

        if ($tenant->exists) {
            $tenant->save();
        }

        return true;
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        if (! $this->isolated($tenant)) {
            return $this->schemaManager->deleteDatabase($tenant);
        }

        /** @var Tenant $tenant */
        $identifier = $tenant->isolated_database_cluster_id;
        if ($identifier) {
            $this->destinationFor($tenant)->teardown($identifier);
        }

        return true;
    }

    /**
     * Route to the destination that actually provisioned this tenant (ticket 08, point 2:
     * the explicit marker is what lets teardown — and anything else that needs to branch —
     * route correctly without guessing). Three-way as of ticket 16. Defaults to Laravel Cloud
     * for tenants that predate the marker (e.g. entreport's pilot, cut over before ticket 13) —
     * that fallback is a real, correct identification of an old tenant, never a live choice for
     * a new one (see {@see createDatabase()}'s own docblock).
     */
    protected function destinationFor(TenantWithDatabase $tenant): ProvisioningDestination
    {
        /** @var Tenant $tenant */
        return match ($tenant->isolatedDatabaseDestination()) {
            'customer_supplied' => $this->customerSupplied,
            'gcp_cloud_sql' => $this->gcpCloudSql,
            default => $this->laravelCloud,
        };
    }

    /**
     * The interface only receives a bare name — no tenant — so branching relies on the one
     * structural signal available: a shared-cluster schema name always carries the
     * configured `tenancy.database.prefix` (`tenant_...`); an Isolated Database's name
     * never does (Laravel Cloud auto-names it "production").
     *
     * Ground-truth correction (ticket 04, surfaced running this live): {@see
     * \Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper} calls this on EVERY
     * tenant-context bootstrap, not just at creation — an isolated tenant that always
     * delegated to the schema manager here 404s on every request (it checks the central
     * connection's schema namespace for a schema literally named "production", which
     * never exists). Trusting a matching cluster id as "exists" avoids a live round-trip
     * to the destination on every request; a genuinely unreachable cluster still fails
     * loudly the moment a real query runs against it.
     */
    public function databaseExists(string $name): bool
    {
        if (! str_starts_with($name, config('tenancy.database.prefix', ''))) {
            return true;
        }

        return $this->schemaManager->databaseExists($name);
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        // stancl's contract doesn't pass $tenant here — $baseConfig already has stancl's
        // DatabaseConfig::tenantConfig() merged in, which only ever carries a `host` key
        // when createDatabase() above set tenancy_db_host (an Isolated-Database tenant); a
        // shared-schema tenant's $baseConfig still has the central `pgsql` connection's own
        // host untouched. That's the only signal available to branch on at this hook.
        if (($baseConfig['host'] ?? null) !== config('database.connections.pgsql.host')) {
            $baseConfig['database'] = $databaseName;
            $baseConfig['sslmode'] = 'verify-full';
            // Not a pinned cert: this hook serves BOTH destinations' live traffic with no
            // tenant/destination context to branch on (ticket 12/13) — see
            // IsolatedDatabaseTrustStore's own docblock for why that rules out pinning.
            $baseConfig['sslrootcert'] = IsolatedDatabaseTrustStore::sslRootCert();

            return $baseConfig;
        }

        return $this->schemaManager->makeConnectionConfig($baseConfig, $databaseName);
    }

    protected function isolated(TenantWithDatabase $tenant): bool
    {
        return method_exists($tenant, 'isIsolatedDatabase') && $tenant->isIsolatedDatabase();
    }

    /**
     * Laravel Cloud cluster names are user-facing in its dashboard — prefix with the app so
     * clusters from different consumers of the same org token stay distinguishable (ticket 05
     * flagged app-deploy vs. Database-API usage mixing in the org's own usage dashboard).
     */
    protected function clusterName(TenantWithDatabase $tenant): string
    {
        /** @var Tenant $tenant */
        return 'tower-'.$tenant->getTenantKey();
    }
}
