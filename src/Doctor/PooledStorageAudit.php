<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\PostgresRls\Audits\AuditScope;
use Rushing\PostgresRls\Audits\ConnectionConfig;
use Rushing\PostgresRls\Audits\CoverageAudit;
use Rushing\PostgresRls\Audits\FrameAudit;
use Rushing\PostgresRls\Audits\OwnerExposureAudit;
use Rushing\PostgresRls\Audits\RoleAudit;
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
    public function __construct(private readonly ConnectionResolverInterface $db) {}

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
                $probes[(string) $pool] = $this->registerProbe((string) $pool, $tenants->first());
            }

            $scope = $this->scopeFor($byPool->keys()->map(fn ($p) => (string) $p)->all(), array_values($probes));
            $connections = new ConnectionConfig((array) Config::get('database.connections', []));

            return [
                ...(new CoverageAudit($this->db, $scope))->run(),
                ...(new RoleAudit($this->db, $scope, $connections))->run(),
                ...(new OwnerExposureAudit($this->db, $scope, $connections))->run(),
                ...(new FrameAudit($this->db, $scope, $connections))->run(),
            ];
        } catch (Throwable $e) {
            return [Finding::fail('tenancy.pooled-storage', 'Could not build a probe connection for a pool: '.$e->getMessage())];
        } finally {
            foreach ($probes as $name) {
                DB::purge($name);
                self::forgetConnection($name);
            }
        }
    }

    /**
     * The scope the rushing audits measure: every pool schema, the central catalogue connection, the
     * probe connections as the scoped set, and this package's own column/setting/force choices.
     *
     * @param  list<string>  $pools
     * @param  list<string>  $probeConnections
     */
    public function scopeFor(array $pools, array $probeConnections): AuditScope
    {
        $rls = (array) Config::get('postgres-rls', []);

        return new AuditScope(
            schemas: array_map(fn (string $pool) => Tenant::poolSchemaFor($pool), $pools),
            connection: TenancyConnections::central(),
            scopedConnections: $probeConnections,
            maintenanceConnections: [],
            column: (string) ($rls['column'] ?? 'tenant_id'),
            setting: (string) Config::get('beam.tenancy.pooled.session_setting', 'app.tenant_id'),
            exclude: (array) ($rls['exclude'] ?? ['migrations']),
            force: (bool) Config::get('beam.tenancy.pooled.force_rls', false),
        );
    }

    /**
     * Register the connection the hybrid manager would build for this tenant, under a probe name.
     */
    protected function registerProbe(string $pool, Tenant $tenant): string
    {
        $name = "beam_pool_probe_{$pool}";

        Config::set("database.connections.{$name}", $tenant->database()->connection());
        $this->db->purge($name);

        return $name;
    }

    /**
     * Remove a transient connection's config outright — the repository's `offsetUnset` leaves a null
     * entry behind, which `ConnectionConfig` and `array_key_exists` both still see.
     */
    protected static function forgetConnection(string $name): void
    {
        $connections = (array) Config::get('database.connections', []);
        unset($connections[$name]);
        Config::set('database.connections', $connections);
    }
}
