<?php

namespace Splicewire\Beam\Tenancy\Destinations;

/**
 * The Isolated Database provisioning-destination seam (tenant-database-upsell ticket 08/13):
 * connect a tenant to a dedicated Postgres server, whether tower provisions and owns that
 * server ({@see IsolatedDatabaseDestination}, a Laravel Cloud cluster) or a customer supplies
 * and owns their own ({@see CustomerSuppliedDatabaseDestination}). `HybridPostgresTenantDatabaseManager`
 * and `Splicewire\Tower\Provisioning\MigrateTenantToIsolatedDatabase` depend on this interface,
 * never a concrete destination, so a second implementation slots in without touching either.
 *
 * Deliberately not named/shaped after `splicewire/laravel-beam-provision`'s `Destination`
 * (app-deploy provisioning) — same "provisioning-destination seam" vocabulary, a genuinely
 * different contract (database connections, not app deploys), per that map's own note that the
 * two seams share vocabulary but are separate implementations, decided independently.
 */
interface ProvisioningDestination
{
    /**
     * Establish this tenant's database on the destination — provisioning a fresh resource
     * (Laravel Cloud: a new cluster) or validating and adopting an existing one (customer-
     * supplied: the tenant's submitted connection details) — and hand back its connection.
     *
     * @param  array<string, mixed>  $params  Implementation-specific: {@see IsolatedDatabaseDestination}
     *                                        expects `['name' => string]`; {@see CustomerSuppliedDatabaseDestination} expects the
     *                                        submitted `['hostname' => ..., 'port' => ..., 'database' => ..., 'username' => ...,
     *                                        'password' => ..., 'sslmode' => ...]`.
     * @return array{identifier: string, database: string, connection: array{hostname: string, port: int, username: string, password: string}}
     */
    public function provision(array $params): array;

    /**
     * Install this family's tenant-schema extensions on the destination where the destination
     * permits it. Never silently degrades: a destination that refuses `CREATE EXTENSION`
     * (a customer server with no superuser grant) must surface that as a real preflight
     * failure via {@see verifyExtensions()}, not swallow it.
     *
     * @param  array{hostname: string, port: int, username: string, password: string}  $connection
     */
    public function installExtensions(array $connection, string $database): void;

    /**
     * A read-only check of which of this family's required extensions are missing on the
     * destination — used both as a migration-verification step and as the customer-supplied
     * preflight ticket 08 requires.
     *
     * @param  array{hostname: string, port: int, username: string, password: string}  $connection
     * @return array<int, string> the extensions still missing
     */
    public function verifyExtensions(array $connection, string $database): array;

    /**
     * End tower's relationship to this destination. For a destination tower owns the lifecycle
     * of (Laravel Cloud), this deletes the provisioned resource. For a destination tower does
     * not own (customer-supplied), this must be disconnect-only (ticket 08, point 6) — tower
     * must never issue a destructive command against infrastructure it doesn't own.
     */
    public function teardown(string $identifier): void;
}
