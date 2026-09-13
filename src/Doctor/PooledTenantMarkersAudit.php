<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Tenancy\Pools\PoolRegistry;
use Splicewire\Beam\Tenancy\Tenant;
use Throwable;

/**
 * Every pooled tenant's connection markers agree with its key (pooled-storage ticket 06).
 *
 * ## The question
 * For each tenant carrying a `pool` marker: does its `tenancy_db_session_settings` carry the configured
 * session setting with the tenant's OWN key as the value, and does its `tenancy_db_name` name the pool's
 * schema? Those two internals are what the hybrid manager turns into a connection — the setting is what
 * the row-level-security policy scopes on. A tenant whose setting names a different key would read
 * THAT tenant's rows on every request, silently, with a 200; a tenant whose `db_name` names the wrong
 * schema bootstraps somewhere else entirely. So this GATES.
 *
 * ## What a Pass does not prove
 * That the pool schema is prepared (the rushing coverage audit), that the connecting role is not the
 * owner (the rushing role audit), or that the setting is actually applied on a live connection (the
 * rushing frame audit). This audit reads the central `tenants` table only; it proves the markers, not
 * the database. Over ZERO pooled tenants it is inconclusive — "nothing here", not "measured clean".
 */
class PooledTenantMarkersAudit implements DoctorAudit
{
    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        try {
            $pooled = Tenant::query()->whereNotNull('data->pool')->get();
        } catch (Throwable $e) {
            return [Finding::inconclusive('tenancy.pooled-markers', 'Could not read the tenants table: '.$e->getMessage())];
        }

        if ($pooled->isEmpty()) {
            return [Finding::inconclusive('tenancy.pooled-markers', 'No pooled tenant on this host — nothing to check (not "measured clean").')];
        }

        $setting = (string) config('beam.tenancy.pooled.session_setting', 'app.tenant_id');
        $defects = [];

        /** @var Tenant $tenant */
        foreach ($pooled as $tenant) {
            $key = (string) $tenant->getTenantKey();
            $settings = $tenant->tenancy_db_session_settings;
            $carried = is_array($settings) ? ($settings[$setting] ?? null) : null;

            if ($carried !== $key) {
                $defects[] = "{$key}: session setting {$setting} is ".($carried === null ? 'missing' : "'{$carried}'").", not the tenant's own key";
            }

            try {
                $expected = app(PoolRegistry::class)->remoteConnectionFor((string) $tenant->pool);
            } catch (Throwable $e) {
                $defects[] = "{$key}: {$e->getMessage()}";
                $expected = $tenant->tenancy_db_connection;
            }

            // A tenant pointing at another server than its pool's would read (or write) a different
            // database's copy of the pool — or none (ticket 13).
            if (($tenant->tenancy_db_connection ?: null) !== $expected) {
                $defects[] = "{$key}: db_connection is '".($tenant->tenancy_db_connection ?: 'central')."', not pool '{$tenant->pool}'s server '".($expected ?? 'central')."'";
            }

            if ($tenant->tenancy_db_name !== $tenant->poolSchema()) {
                $defects[] = "{$key}: db_name is '".($tenant->tenancy_db_name ?? 'unset')."', not the pool schema '{$tenant->poolSchema()}'";
            }
        }

        if ($defects !== []) {
            return [Finding::fail('tenancy.pooled-markers', count($defects).' defect(s) across '.$pooled->count().' pooled tenant(s): '.implode('; ', $defects))];
        }

        return [Finding::pass('tenancy.pooled-markers', $pooled->count()." pooled tenant(s) carry their own key under {$setting} and name their pool schema.")];
    }
}
