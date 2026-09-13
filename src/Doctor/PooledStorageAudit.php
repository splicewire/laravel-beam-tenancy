<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\PostgresRls\Audits\AuditScope;
use Rushing\PostgresRls\Audits\ConnectionConfig;
use Rushing\PostgresRls\Audits\CoverageAudit;
use Rushing\PostgresRls\Audits\FrameAudit;
use Rushing\PostgresRls\Audits\OwnerExposureAudit;
use Rushing\PostgresRls\Audits\RoleAudit;
use Splicewire\Beam\Tenancy\Pools\PoolRegistry;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;
use Splicewire\Beam\Tenancy\Tenant;
use Throwable;

/**
 * The host wrapper the rushing audits ask for (pooled-storage ticket 06): `rushing/laravel-postgres-rls`
 * measures schemas and connection NAMES it is handed; this package knows which schemas are pools and
 * how a pooled tenant's connection is built. So this audit composes the four — coverage, role, owner
 * exposure, frame — over every pool any tenant is marked with, and hands them a PROBE connection per
 * pool: the connection config the hybrid manager would build for one pooled tenant of that pool
 * (non-owner role, session setting, pool in `search_path`), registered under `beam_pool_probe_<pool>`
 * for the duration of the run and purged after. A pooled tenant's real connection exists only inside a
 * request frame, which a doctor run never has; the probe is the same config, opened on purpose.
 *
 * GATES: coverage, role and owner exposure are the three invariants a pooled tenant's isolation rests
 * on, and each rushing audit already fails loud on an unreadable schema or an unopenable connection.
 * The frame audit is advisory in the rushing package and stays so here.
 *
 * ## What a Pass does not prove
 * That the CENTRAL markers are right ({@see PooledTenantMarkersAudit} — a tenant whose setting names
 * another key would probe as that key and pass every check here), or anything about a schema tenant.
 * Over ZERO pooled tenants the run is inconclusive: nothing here is not measured clean.
 */
class PooledStorageAudit implements DoctorAudit
{
    /**
     * @param  bool  $gates  true = the three gate audits (coverage, role, owner exposure); false = the
     *                       advisory frame audit alone. Two registrations, because a Warn from an advisory
     *                       sub-audit inside a GATE registration would fail a `--floor=warn` doctor run.
     */
    public function __construct(private readonly DatabaseManager $db, private readonly bool $gates = true) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        try {
            $byPool = Tenant::query()->whereNotNull('data->pool')->get()->groupBy(fn (Tenant $tenant) => (string) $tenant->pool);
        } catch (Throwable $e) {
            return [Finding::inconclusive('tenancy.pooled-storage', 'Could not read the tenants table: '.$e->getMessage())];
        }

        if ($byPool->isEmpty()) {
            return [Finding::inconclusive('tenancy.pooled-storage', 'No pooled tenant on this host — nothing to measure (not "measured clean").')];
        }

        $probes = [];

        try {
            foreach ($byPool as $pool => $tenants) {
                // Recorded BEFORE the config write, so a throw in between still reaches the purge.
                $name = 'beam_pool_probe_'.$pool;
                $probes[(string) $pool] = $name;
                $this->registerProbe($name, $tenants->first());
            }

            $connections = new ConnectionConfig((array) Config::get('database.connections', []));
            $registry = app(PoolRegistry::class);
            $findings = [];

            // One audit scope per SERVER (ticket 13): the rushing audits read one catalogue connection.
            foreach ($byPool->keys()->map(fn ($p) => (string) $p)->groupBy(fn (string $pool) => $registry->connectionFor($pool)) as $server => $pools) {
                $pools = $pools->all();
                $scope = $this->scopeFor($pools, array_values(array_intersect_key($probes, array_flip($pools))), (string) $server);

                $findings = [...$findings, ...($this->gates
                    ? [
                        ...(new CoverageAudit($this->db, $scope))->run(),
                        ...(new RoleAudit($this->db, $scope, $connections))->run(),
                        ...(new OwnerExposureAudit($this->db, $scope, $connections))->run(),
                        ...$this->centralAccess($pools, $probes, (string) $server),
                    ]
                    : (new FrameAudit($this->db, $scope, $connections))->run())];
            }

            return $findings;
        } catch (Throwable $e) {
            return [Finding::fail('tenancy.pooled-storage', 'Could not build a probe connection for a pool: '.$e->getMessage())];
        } finally {
            foreach ($probes as $name) {
                TenancyConnections::forget($name);
            }
        }
    }

    /**
     * The scope the rushing audits measure: the pool schemas on one server, that server's catalogue connection, the
     * probe connections as the scoped set, and this package's own column/setting/force choices.
     *
     * @param  list<string>  $pools
     * @param  list<string>  $probeConnections
     */
    public function scopeFor(array $pools, array $probeConnections, ?string $server = null): AuditScope
    {
        $rls = (array) Config::get('postgres-rls', []);

        return new AuditScope(
            schemas: array_map(fn (string $pool) => Tenant::poolSchemaFor($pool), $pools),
            connection: $server ?? TenancyConnections::central(),
            scopedConnections: $probeConnections,
            maintenanceConnections: [],
            column: (string) ($rls['column'] ?? 'tenant_id'),
            setting: (string) Config::get('beam.tenancy.pooled.session_setting', 'app.tenant_id'),
            exclude: (array) ($rls['exclude'] ?? ['migrations']),
            force: (bool) Config::get('beam.tenancy.pooled.force_rls', false),
        );
    }

    /**
     * GATE: can each probe's role read the central tables a tenant frame falls through to? A schema tenant's
     * owner connection always can; a pooled role can only once pools:migrate granted it (`central_access`).
     * Measured with `has_table_privilege` on the central catalogue — no probe connection opened.
     *
     * @param  list<string>  $pools
     * @param  array<string, string>  $probes
     * @return list<Finding>
     */
    protected function centralAccess(array $pools, array $probes, string $server): array
    {
        $check = 'tenancy.pooled-storage.central-access';

        if (! Config::get('beam.tenancy.pooled.central_access', true)) {
            return [];
        }

        $findings = [];

        foreach ($pools as $pool) {
            // A pool on another server has no central `public` behind it (ADR-0002).
            if (app(PoolRegistry::class)->remoteConnectionFor($pool) !== null) {
                continue;
            }

            $role = (string) Config::get("database.connections.{$probes[$pool]}.username");
            $missing = array_map(fn ($row) => $row->relname, $this->db->connection($server)->select(
                "select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p') and not has_table_privilege(?, c.oid, 'SELECT, INSERT, UPDATE, DELETE') order by c.relname",
                [$role]
            ));

            $findings[] = $missing === []
                ? Finding::pass($check, "Pool '{$pool}': role {$role} has DML on every central table in public, as a schema tenant's connection does.")
                : Finding::fail($check, sprintf("Pool '%s': role %s lacks DML on %d central table(s) a tenant frame falls through to (%s%s) — an unpinned central read in a pooled tenant is `permission denied`. Run splicewire:beam:tenancy:pools:migrate %s.", $pool, $role, count($missing), implode(', ', array_slice($missing, 0, 5)), count($missing) > 5 ? ', …' : '', $pool));
        }

        return $findings;
    }

    /**
     * Register the connection the hybrid manager would build for this tenant, under a probe name.
     */
    protected function registerProbe(string $name, Tenant $tenant): void
    {
        Config::set("database.connections.{$name}", $tenant->database()->connection());
        $this->db->purge($name);
    }
}
