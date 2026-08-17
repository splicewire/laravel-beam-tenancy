<?php

namespace Splicewire\Beam\Tenancy\Destinations;

use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter;
use Splicewire\Beam\Provision\Gcp\WorkloadIdentityCredentialResolver;
use Splicewire\Beam\Provision\Tofu\TenantDatabaseDeployConfig;
use Splicewire\Beam\Provision\Tofu\TenantDatabaseRootConfigRenderer;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;
use Splicewire\Beam\Provision\Tofu\TofuApplyRequest;
use Splicewire\Beam\Provision\Tofu\TofuApplyResult;

/**
 * The replacement managed {@see ProvisioningDestination} (tenant-database-upsell ticket 16) —
 * Laravel Cloud is retired for new provisioning, this is its successor: a public-IP,
 * Authorized-Networks-allowlisted GCP Cloud SQL instance (ticket 15's `tenant-database`
 * module, extended for a public-IP consumer) driven through `laravel-beam-provision`'s real
 * `TofuApplyDispatcher` apply pipeline — never `TenantRootConfigRenderer`'s Cloud-Run-coupled
 * shape, which this destination has no use for (it deploys no app; see
 * {@see TenantDatabaseRootConfigRenderer}).
 *
 * Credential resolution is keyless end to end (ticket 16 point 2, no human in the loop, per
 * ticket 08's self-serve requirement): mint tower's own short-lived OIDC identity token
 * ({@see IdentityTokenMinter}, `laravel-beam-accounts`), federate it into a GCP token via
 * Workload Identity Federation, then impersonate the `beam-provision` service account — the
 * same `roles/iam.serviceAccountTokenCreator` impersonation pattern
 * `gcp-cloud-run-provisioning` ticket 02 uses for a human operator's own `gcloud` session,
 * just with tower's own issuer as the trust anchor instead of a person.
 *
 * {@see IsolatedDatabaseDestination} stays frozen (Laravel Cloud, entreport only, per this
 * map's revised Destination) — this is an independent implementation, not a subclass or a
 * shared-trait refactor of it, so that frozen code stays untouched.
 */
class GcpCloudSqlDestination implements ProvisioningDestination
{
    public function __construct(
        protected string $project,
        protected string $region,
        protected string $serviceAccountEmail,
        protected string $workloadIdentityProvider,
        protected string $modulesDir,
        protected string $rootConfigsRoot,
        protected array $extensions,
        protected array $authorizedNetworks,
        protected IdentityTokenMinter $identityTokenMinter,
        protected WorkloadIdentityCredentialResolver $credentialResolver,
        protected TenantDatabaseRootConfigRenderer $renderer,
        protected TofuApplyDispatcher $tofu,
    ) {}

    /**
     * @param  array{name: string}  $params  `name` is the tenant id the module's resources are named from.
     * @return array{identifier: string, database: string, connection: array{hostname: string, port: int, username: string, password: string}}
     */
    public function provision(array $params): array
    {
        $tenantId = $params['name'];

        $this->render($tenantId);

        $credential = $this->resolveCredential();

        $result = $this->tofu->apply($credential, new TofuApplyRequest($tenantId, $this->rootConfigDirFor($tenantId)));

        if (! $result->successful) {
            throw new RuntimeException("GcpCloudSqlDestination::provision(): tofu apply failed for tenant `{$tenantId}`: ".$this->diagnostics($result));
        }

        $outputs = $result->outputs ?? [];
        $secretId = $outputs['secret_id']['value'] ?? null;
        $publicIp = $outputs['public_ip_address']['value'] ?? null;
        $database = $outputs['database_name']['value'] ?? null;

        if (! $secretId || ! $publicIp || ! $database) {
            throw new RuntimeException("GcpCloudSqlDestination::provision(): tofu apply for tenant `{$tenantId}` succeeded but is missing expected outputs (secret_id/public_ip_address/database_name) — got: ".json_encode($outputs));
        }

        $stored = $this->readSecret($credential, $secretId);

        return [
            'identifier' => $tenantId,
            'database' => $database,
            'connection' => [
                'hostname' => $publicIp,
                'port' => 5432,
                'username' => $stored['username'],
                'password' => $stored['password'],
            ],
        ];
    }

    /**
     * @param  array{hostname: string, port: int, username: string, password: string}  $connection
     */
    public function installExtensions(array $connection, string $database): void
    {
        $pdo = $this->connect($connection, $database);

        foreach ($this->extensions as $extension) {
            $pdo->exec('CREATE EXTENSION IF NOT EXISTS "'.str_replace('"', '', $extension).'"');
        }
    }

    /**
     * @param  array{hostname: string, port: int, username: string, password: string}  $connection
     * @return array<int, string>
     */
    public function verifyExtensions(array $connection, string $database): array
    {
        $pdo = $this->connect($connection, $database);
        $installed = $pdo->query('SELECT extname FROM pg_extension')->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_diff($this->extensions, $installed));
    }

    /**
     * @param  string  $identifier  The tenant id `provision()` returned — re-renders the SAME
     *                              root config so `tofu destroy` resolves the same resource addresses
     *                              (mirrors `CloudRunDestination::teardown()`'s own re-render-from-id pattern).
     */
    public function teardown(string $identifier): void
    {
        $this->render($identifier);

        $credential = $this->resolveCredential();

        $result = $this->tofu->destroy($credential, new TofuApplyRequest($identifier, $this->rootConfigDirFor($identifier)));

        if (! $result->successful) {
            throw new RuntimeException("GcpCloudSqlDestination::teardown(): tofu destroy failed for tenant `{$identifier}`: ".$this->diagnostics($result));
        }
    }

    protected function render(string $tenantId): void
    {
        $this->renderer->render(
            new TenantDatabaseDeployConfig(
                tenantId: $tenantId,
                projectId: $this->project,
                region: $this->region,
                secretAccessorMember: "serviceAccount:{$this->serviceAccountEmail}",
                ipv4Enabled: true,
                authorizedNetworks: $this->authorizedNetworks,
            ),
            $this->rootConfigDirFor($tenantId),
        );
    }

    /**
     * Mint tower's own short-lived identity token and federate it into a GCP token that
     * impersonates `$serviceAccountEmail` — resolved fresh on every call (never cached across
     * apply/destroy invocations), matching this family's ask-fresh-each-use credential
     * discipline.
     */
    protected function resolveCredential(): string
    {
        $subjectToken = $this->identityTokenMinter->mint($this->workloadIdentityProvider);

        return $this->credentialResolver->resolve($subjectToken, $this->workloadIdentityProvider, $this->serviceAccountEmail);
    }

    /**
     * @return array{connection_name: string, database: string, username: string, password: string}
     */
    protected function readSecret(string $credential, string $secretId): array
    {
        $response = Http::withToken($credential)
            ->get("https://secretmanager.googleapis.com/v1/projects/{$this->project}/secrets/{$secretId}/versions/latest:access");

        if ($response->failed()) {
            throw new RuntimeException("GcpCloudSqlDestination: could not read Secret Manager secret `{$secretId}` ({$response->status()}): {$response->body()}");
        }

        $encoded = $response->json('payload.data');
        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException("GcpCloudSqlDestination: Secret Manager secret `{$secretId}` returned no payload data.");
        }

        $decoded = json_decode(base64_decode($encoded), true);
        if (! is_array($decoded) || ! isset($decoded['username'], $decoded['password'])) {
            throw new RuntimeException("GcpCloudSqlDestination: Secret Manager secret `{$secretId}` did not decode to the expected credential shape.");
        }

        return $decoded;
    }

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

    protected function rootConfigDirFor(string $tenantId): string
    {
        return rtrim($this->rootConfigsRoot, '/')."/{$tenantId}";
    }

    /** Surfaces the underlying popcorn Result's error/stderr instead of losing it to a generic message. */
    protected function diagnostics(TofuApplyResult $result): string
    {
        $popcorn = $result->popcornResult;

        return trim(($popcorn->error ?? 'no error message').($popcorn->stderr !== '' ? " | stderr: {$popcorn->stderr}" : ''));
    }
}
