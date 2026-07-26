<?php

use Splicewire\Beam\MultiTenancy\Tests\Fixtures\TestTenant;

it('resolves the system tenant by its role marker', function () {
    $tenant = TestTenant::create(['id' => 'acme', 'name' => 'Acme']);
    $tenant->markAsSystem();

    $resolved = TestTenant::system();

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe('acme')
        ->and($resolved->isSystem())->toBeTrue();
});

it('returns null when no tenant is marked as system', function () {
    TestTenant::create(['id' => 'acme', 'name' => 'Acme']);

    expect(TestTenant::system())->toBeNull();
});

it('honors a config/env override for the slug and defaults to system', function () {
    expect(TestTenant::systemSlug())->toBe('system');

    config(['beam-multi-tenancy.system_tenant.slug' => 'hub']);

    expect(TestTenant::systemSlug())->toBe('hub');
});

it('round-trips markAsSystem/isSystem through the database', function () {
    $tenant = TestTenant::create(['id' => 'acme', 'name' => 'Acme']);

    expect($tenant->isSystem())->toBeFalse();

    $tenant->markAsSystem();

    $fresh = TestTenant::find('acme');

    expect($fresh->isSystem())->toBeTrue()
        ->and($fresh->role)->toBe(TestTenant::systemRole());

    // The role is persisted inside the `data` JSON column, queryable via data->role.
    expect(
        TestTenant::query()->where('data->role', TestTenant::systemRole())->exists()
    )->toBeTrue();
});

it('provisions the system tenant idempotently', function () {
    $tenant = TestTenant::provisionSystem(['name' => 'System']);

    expect($tenant->id)->toBe(TestTenant::systemSlug())
        ->and($tenant->isSystem())->toBeTrue();

    TestTenant::provisionSystem(['name' => 'System']);

    expect(TestTenant::query()->count())->toBe(1);
});

it('resolves a custom role override', function () {
    config(['beam-multi-tenancy.system_tenant.role' => 'internal']);

    $tenant = TestTenant::create(['id' => 'acme', 'name' => 'Acme']);
    $tenant->markAsSystem();

    $resolved = TestTenant::system();

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe('acme')
        ->and($resolved->role)->toBe('internal');

    // Resolution is driven by the data->role JSON path, not a top-level column.
    expect(
        TestTenant::query()->where('data->role', 'internal')->exists()
    )->toBeTrue();
});
