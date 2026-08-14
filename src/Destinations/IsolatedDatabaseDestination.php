<?php

namespace Splicewire\Beam\Tenancy\Destinations;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The Isolated Database provisioning destination (tenant-database-upsell ticket 02/04):
 * a dedicated Laravel Cloud Serverless Postgres cluster per tenant, used purely as an
 * external managed-Postgres vendor — splicewire-app itself never deploys onto Laravel Cloud.
 *
 * Two narrow jobs, per ticket 02's resolution: (a) connection resolution — provision a
 * cluster and hand back its connection array; (b) a post-create Postgres-extension-install
 * step, since a fresh isolated database doesn't see `public`'s extensions the way a schema
 * tenant does (ticket 01's finding). Provisioning orchestration stays with the hybrid
 * manager; teardown/orchestration policy is not this class's job.
 *
 * Ground-truth correction (ticket 04, surfaced live against the real Laravel Cloud org):
 * raw HTTP POSTs against Laravel Cloud's REST API do not work — they redirect to the
 * dashboard instead of writing (confirmed via curl; matches the finding already recorded
 * on the target's own record, `$SCRIPTORIUM_CORPUS/deployments/_targets/laravel-cloud/target.md`
 * — GETs work over bearer-token curl, writes don't). Only the official `cloud` CLI can create
 * a cluster. This class shells out to it rather than doing a REST call — the seam ticket 02
 * described (`POST /databases/clusters`) never actually existed as a usable path.
 *
 * Implements {@see ProvisioningDestination} (ticket 13) — `provision()`/`teardown()` are this
 * class's `createCluster()`/`deleteCluster()` under the seam's generalized names.
 */
class IsolatedDatabaseDestination implements ProvisioningDestination
{
    /**
     * @param  string  $cloudBinary  Absolute path to (or PATH-resolvable name of) the `cloud` CLI.
     * @param  string|null  $apiToken  Org-scoped Laravel Cloud API token. The CLI itself has no env-var
     *                                 auth path — it only reads `~/.config/cloud/config.json` — so this class materializes that file
     *                                 under a dedicated, dedicated-per-process HOME directory rather than touching the real user's home.
     */
    public function __construct(
        protected string $cloudBinary,
        protected ?string $apiToken,
        protected string $region,
        protected string $clusterType,
        protected array $extensions,
        protected string $cliHomeDir,
    ) {}

    /**
     * Provision a fresh, dedicated Postgres cluster for one tenant and return its connection
     * details plus the default database name Laravel Cloud auto-creates on the cluster
     * (`schemas[0].name`, "production" today — Laravel Cloud's own "database (schema)" unit,
     * a real Postgres database inside the cluster, not a Postgres schema).
     *
     * @param  array{name: string}  $params
     * @return array{identifier: string, database: string, connection: array{hostname: string, port: int, username: string, password: string}}
     */
    public function provision(array $params): array
    {
        $name = $params['name'];

        $result = $this->runCli([
            'database-cluster:create',
            "--name={$name}",
            "--type={$this->clusterType}",
            "--region={$this->region}",
            '--json',
        ]);

        $database = $result['schemas'][0]['name'] ?? null;
        if (! $database) {
            throw new RuntimeException("Laravel Cloud cluster '{$name}' was created but returned no default database — cannot resolve a connection.");
        }

        return [
            'identifier' => $result['id'],
            'database' => $database,
            'connection' => [
                'hostname' => $result['connection']['hostname'],
                'port' => (int) $result['connection']['port'],
                'username' => $result['connection']['username'],
                'password' => $result['connection']['password'],
            ],
        ];
    }

    /**
     * Install the Postgres extensions today's schema tenants borrow from `public` via
     * `search_path` (ticket 01's finding) — a fresh isolated database sees none of them.
     * Connects directly over the network (Laravel Cloud's Serverless Postgres has no
     * IP-allowlist/VPC option — credentials + SSL only, per ticket 02's flagged item).
     *
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
     * A lightweight reachability + extension-presence check, used to verify a migration's
     * destination before cutover (ticket 04's pilot verification step).
     */
    public function verifyExtensions(array $connection, string $database): array
    {
        $pdo = $this->connect($connection, $database);
        $installed = $pdo->query('SELECT extname FROM pg_extension')->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_diff($this->extensions, $installed));
    }

    public function teardown(string $identifier): void
    {
        $this->runCli(['database-cluster:delete', $identifier, '--force', '--json']);
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

    /**
     * @return array<string, mixed>
     */
    protected function runCli(array $arguments): array
    {
        $this->ensureCliAuth();

        $process = new Process(
            [$this->cloudBinary, ...$arguments, '-n'],
            env: ['HOME' => $this->cliHomeDir],
            timeout: 120,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Laravel Cloud CLI command failed: `cloud {$this->argsForError($arguments)}` — ".trim($process->getErrorOutput().' '.$process->getOutput()));
        }

        $decoded = json_decode($process->getOutput(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Laravel Cloud CLI returned non-JSON output for `cloud {$this->argsForError($arguments)}`: ".$process->getOutput());
        }

        if (($decoded['error'] ?? false) === true) {
            throw new RuntimeException('Laravel Cloud CLI error: '.($decoded['message'] ?? json_encode($decoded)));
        }

        return $decoded;
    }

    protected function argsForError(array $arguments): string
    {
        return implode(' ', array_filter($arguments, fn ($a) => ! Str::contains($a, 'password')));
    }

    /**
     * Materialize the CLI's own config-file auth (its only auth path — no env var) under a
     * dedicated HOME this process controls, so tower's provisioning process never depends on
     * (or clobbers) a real developer's `~/.config/cloud/config.json`.
     */
    protected function ensureCliAuth(): void
    {
        if (! $this->apiToken) {
            throw new RuntimeException('No Laravel Cloud API token configured (services.laravel_cloud.token / LARAVEL_CLOUD_API_TOKEN).');
        }

        $configDir = "{$this->cliHomeDir}/.config/cloud";
        $configFile = "{$configDir}/config.json";

        if (File::exists($configFile)) {
            $existing = json_decode(File::get($configFile), true);
            if (($existing['api_tokens'][0] ?? null) === $this->apiToken) {
                return;
            }
        }

        File::ensureDirectoryExists($configDir);
        File::put($configFile, json_encode(['api_tokens' => [$this->apiToken]], JSON_PRETTY_PRINT));
    }
}
