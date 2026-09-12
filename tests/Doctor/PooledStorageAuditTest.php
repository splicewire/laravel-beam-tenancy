<?php

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Tenancy\Doctor\PooledStorageAudit;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * pooled-storage ticket 06 — the wrapper's composition, provable without Postgres. The measured run is
 * in tests/Postgres.
 */
it('is inconclusive over zero pooled tenants and opens no connection', function () {
    Tenant::create(['id' => 'plain', 'name' => 'Plain', 'slug' => 'plain']);

    $finding = app(PooledStorageAudit::class)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeFalse()
        ->and(array_key_exists('beam_pool_probe_default', config('database.connections')))->toBeFalse();
});

it('scopes the rushing audits to every pool schema, the central connection, the probes, and this package\'s setting and force choices', function () {
    config([
        'beam.tenancy.pooled.schema_prefix' => 'pool_',
        'beam.tenancy.pooled.session_setting' => 'app.tenant_id',
        'beam.tenancy.pooled.force_rls' => true,
        'postgres-rls.column' => 'tenant_id',
        'postgres-rls.exclude' => ['migrations', 'audit_log'],
    ]);

    $scope = app(PooledStorageAudit::class)->scopeFor(['default', 'eu'], ['beam_pool_probe_default', 'beam_pool_probe_eu']);

    expect($scope->schemas)->toBe(['pool_default', 'pool_eu'])
        ->and($scope->connection)->toBe('testing')
        ->and($scope->scopedConnections)->toBe(['beam_pool_probe_default', 'beam_pool_probe_eu'])
        ->and($scope->setting)->toBe('app.tenant_id')
        ->and($scope->force)->toBeTrue()
        ->and($scope->exclude)->toBe(['migrations', 'audit_log']);
});

it('registers the frame audit separately and advisory, so its Warn cannot fail a warn-floor run', function () {
    $manifest = app(Splicewire\Beam\Doctor\BeamDoctorManifest::class);
    $registrations = collect($manifest->registrations())->keyBy(fn ($r) => $r->audit);

    expect($registrations->get(PooledStorageAudit::class)?->gate)->toBeTrue()
        ->and($registrations->get(Splicewire\Beam\Tenancy\Doctor\PooledStorageFrameAudit::class)?->gate)->toBeFalse()
        ->and($registrations->get(Splicewire\Beam\Tenancy\Doctor\PooledTenantMarkersAudit::class)?->gate)->toBeTrue();
});
