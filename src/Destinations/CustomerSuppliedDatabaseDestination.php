<?php

namespace Splicewire\Beam\Tenancy\Destinations;

use PDO;
use PDOException;
use RuntimeException;

/**
 * The customer-supplied-Postgres-server {@see ProvisioningDestination} (tenant-database-upsell
 * ticket 08/13): the tenant submits their own connection details for a server they run and own;
 * tower connects to it instead of provisioning one. Two structural differences from
 * {@see IsolatedDatabaseDestination} that fall directly out of ticket 08's design:
 *
 * - **No lifecycle ownership.** `provision()` validates and adopts an already-existing server —
 *   it creates nothing. {@see teardown()} is correspondingly a no-op: tower must never issue a
 *   destructive command against infrastructure it doesn't own (ticket 08, point 6). Disconnecting
 *   (clearing tower's own stored connection attributes) is the caller's job, not this class's.
 * - **The extension preflight can genuinely fail, permanently.** A customer's server may refuse
 *   `CREATE EXTENSION` outright (no superuser grant). That must surface as a clear, tenant-facing
 *   failure (ticket 08, point 4) — never a silently-degraded tenant missing `vector`/
 *   `fuzzystrmatch` search, and never an ambiguous raw PDO exception.
 *
 * TLS posture is NOT customer-choosable (ticket 08, point 7 — matches ticket 10's finding for any
 * Postgres tower reaches over the public internet): every connection this class makes enforces
 * `sslmode=verify-full;sslrootcert=system` regardless of what the submitted params request. A
 * server that can't present a system-CA-verifiable certificate fails the preflight, by design.
 */
class CustomerSuppliedDatabaseDestination implements ProvisioningDestination
{
    /**
     * @param  array<int, string>  $extensions  The Postgres extensions this family's tenant
     *                                          schemas rely on (today's schema tenants borrow these from `public` via `search_path`;
     *                                          an isolated destination doesn't see them — matches {@see IsolatedDatabaseDestination}'s
     *                                          own `$extensions` config, same default list).
     */
    public function __construct(
        protected array $extensions,
    ) {}

    /**
     * Validate the tenant's submitted connection by actually connecting — a customer server's
     * reachability/credentials/TLS posture can only be confirmed live, not inferred. Returns the
     * validated connection back unchanged (nothing was "provisioned"; this is intake, not
     * creation) plus a stable, opaque identifier for logging/tracking (never used for an API
     * call — this class has no API to call one against).
     *
     * @param  array{hostname: string, port: int|string, database: string, username: string, password: string}  $params
     * @return array{identifier: string, database: string, connection: array{hostname: string, port: int, username: string, password: string}}
     */
    public function provision(array $params): array
    {
        foreach (['hostname', 'port', 'database', 'username', 'password'] as $required) {
            if (! isset($params[$required]) || $params[$required] === '') {
                throw new RuntimeException("Customer-supplied database connection is missing required field '{$required}'.");
            }
        }

        $connection = [
            'hostname' => $params['hostname'],
            'port' => (int) $params['port'],
            'username' => $params['username'],
            'password' => $params['password'],
        ];
        $database = $params['database'];

        // Fail loud immediately on a bad/unreachable connection — never adopt a server tower
        // can't actually talk to, per ticket 08's "checked and surfaced, never silent" posture.
        try {
            $this->connect($connection, $database);
        } catch (PDOException $e) {
            throw new RuntimeException("Could not connect to the customer-supplied database at {$connection['hostname']}:{$connection['port']}/{$database}: ".$e->getMessage(), previous: $e);
        }

        return [
            'identifier' => "{$connection['hostname']}:{$connection['port']}/{$database}",
            'database' => $database,
            'connection' => $connection,
        ];
    }

    /**
     * Attempt to install any of this family's required extensions the destination is missing.
     * A customer server may refuse `CREATE EXTENSION` entirely (no superuser grant) — that is
     * NOT swallowed here: it propagates as a clear, named failure so the caller (and ultimately
     * the tenant, per ticket 08 point 4) knows exactly which extension and why, rather than
     * silently shipping a tenant with degraded search.
     *
     * @param  array{hostname: string, port: int, username: string, password: string}  $connection
     */
    public function installExtensions(array $connection, string $database): void
    {
        $pdo = $this->connect($connection, $database);

        foreach ($this->extensions as $extension) {
            $name = str_replace('"', '', $extension);

            try {
                $pdo->exec('CREATE EXTENSION IF NOT EXISTS "'.$name.'"');
            } catch (PDOException $e) {
                throw new RuntimeException(
                    "Customer-supplied database refused to install the required Postgres extension '{$name}' — this destination cannot host an Isolated Database tenant until it's installed by the customer (or the customer's role granted CREATE EXTENSION privileges). Underlying error: ".$e->getMessage(),
                    previous: $e,
                );
            }
        }
    }

    /**
     * A read-only check of which required extensions are missing — the preflight ticket 08
     * requires, and the same verification step {@see IsolatedDatabaseDestination} uses post-copy.
     */
    public function verifyExtensions(array $connection, string $database): array
    {
        $pdo = $this->connect($connection, $database);
        $installed = $pdo->query('SELECT extname FROM pg_extension')->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_diff($this->extensions, $installed));
    }

    /**
     * Disconnect-only, by design (ticket 08, point 6): tower never owns this server's
     * lifecycle, so there is nothing here to delete. Clearing tower's own stored connection
     * attributes is the caller's responsibility, not this class's — a no-op destination method
     * still satisfies the {@see ProvisioningDestination} contract without pretending to own
     * infrastructure it doesn't.
     */
    public function teardown(string $identifier): void
    {
        // Intentionally empty.
    }

    /**
     * TLS posture is fixed, not customer-configurable (ticket 08, point 7): `verify-full`
     * against the OS's full trusted-CA bundle ({@see IsolatedDatabaseTrustStore}, ticket 12) —
     * NOT a single pinned certificate, since a customer's server may present any
     * publicly-trusted CA, not just Laravel Cloud's. A server whose cert doesn't chain to a
     * publicly-trusted root fails here, by design — that IS the preflight failure ticket 08
     * requires ("get your own server a publicly-trusted cert" is a real and correct onboarding
     * constraint), not a bug to work around.
     *
     * @param  array{hostname: string, port: int, username: string, password: string}  $connection
     */
    protected function connect(array $connection, string $database): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=verify-full;sslrootcert=%s',
            $connection['hostname'],
            $connection['port'],
            $database,
            IsolatedDatabaseTrustStore::sslRootCert(),
        );

        return new PDO($dsn, $connection['username'], $connection['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
