<?php

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Tenancy\Doctor\PooledTenantMarkersAudit;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * pooled-storage ticket 06 — the markers a pooled tenant's connection is built from agree with its key.
 */
function pooledTenantWith(string $id, ?string $settingKey, ?string $dbName = 'pool_default'): Tenant
{
    $tenant = Tenant::create(['id' => $id, 'name' => ucfirst($id), 'slug' => $id]);
    $tenant->markPooled('default');
    if ($settingKey !== null) {
        $tenant->setInternal('db_session_settings', ['app.tenant_id' => $settingKey]);
    }
    if ($dbName !== null) {
        $tenant->setInternal('db_name', $dbName);
    }
    $tenant->save();

    return $tenant;
}

beforeEach(fn () => config(['beam.tenancy.pooled.session_setting' => 'app.tenant_id', 'beam.tenancy.pooled.schema_prefix' => 'pool_']));

it('is inconclusive over zero pooled tenants — nothing here is not measured clean', function () {
    Tenant::create(['id' => 'plain', 'name' => 'Plain', 'slug' => 'plain']);

    $finding = (new PooledTenantMarkersAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeFalse();
});

it('passes when every pooled tenant carries its own key and its pool schema', function () {
    pooledTenantWith('alpha', 'alpha');
    pooledTenantWith('bravo', 'bravo');

    $finding = (new PooledTenantMarkersAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeTrue()
        ->and($finding->detail)->toContain('2 pooled tenant(s)');
});

it('fails on a tenant whose setting names another key — the wrong-tenant read a request would make', function () {
    pooledTenantWith('alpha', 'bravo');

    $finding = (new PooledTenantMarkersAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Fail)
        ->and($finding->detail)->toContain("alpha: session setting app.tenant_id is 'bravo'");
});

it('fails on a missing setting and on a db_name that is not the pool schema', function () {
    pooledTenantWith('alpha', null, 'tenant_alpha');

    $finding = (new PooledTenantMarkersAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Fail)
        ->and($finding->detail)->toContain('is missing')
        ->and($finding->detail)->toContain("db_name is 'tenant_alpha', not the pool schema 'pool_default'");
});
