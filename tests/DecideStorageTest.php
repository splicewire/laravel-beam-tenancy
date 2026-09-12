<?php

use Splicewire\Beam\Tenancy\Contracts\ResolvesTenantStorage;
use Splicewire\Beam\Tenancy\Provisioning\DecideStorage;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantStorage;

/**
 * pooled-storage ticket 04 — precedence: requested_storage → storage_resolver → default_for_new_tenants.
 */
function decideStorageFor(array $attributes): Tenant
{
    $tenant = Tenant::create(array_merge(['name' => 'T', 'slug' => $attributes['id']], $attributes));
    (new DecideStorage($tenant))->handle();

    return Tenant::find($attributes['id']);
}

it('leaves a tenant on schema storage by default — the historical behaviour is unchanged', function () {
    config(['beam.tenancy.pooled.default_for_new_tenants' => false, 'beam.tenancy.pooled.storage_resolver' => null]);

    expect(decideStorageFor(['id' => 'a'])->storage())->toBe(TenantStorage::Schema);
});

it('pools a tenant into the default pool when the host default says so', function () {
    config(['beam.tenancy.pooled.default_for_new_tenants' => true, 'beam.tenancy.pooled.default_pool' => 'default']);

    $tenant = decideStorageFor(['id' => 'b']);

    expect($tenant->storage())->toBe(TenantStorage::Pooled)
        ->and($tenant->pool)->toBe('default');
});

it('honours an explicit request over the default and over the resolver', function () {
    config(['beam.tenancy.pooled.default_for_new_tenants' => true]);
    app()->bind('storage-resolver-pooled', fn () => new class implements ResolvesTenantStorage
    {
        public function resolve(Tenant $tenant): ?TenantStorage
        {
            return TenantStorage::Pooled;
        }
    });
    config(['beam.tenancy.pooled.storage_resolver' => 'storage-resolver-pooled']);

    expect(decideStorageFor(['id' => 'c', 'requested_storage' => 'schema'])->storage())->toBe(TenantStorage::Schema)
        ->and(decideStorageFor(['id' => 'd', 'requested_storage' => 'pooled'])->storage())->toBe(TenantStorage::Pooled);
});

it('asks the resolver before the default, and falls through when it declines', function () {
    config(['beam.tenancy.pooled.default_for_new_tenants' => false]);
    app()->bind('storage-resolver-decline', fn () => new class implements ResolvesTenantStorage
    {
        public function resolve(Tenant $tenant): ?TenantStorage
        {
            return $tenant->getTenantKey() === 'free' ? TenantStorage::Pooled : null;
        }
    });
    config(['beam.tenancy.pooled.storage_resolver' => 'storage-resolver-decline']);

    expect(decideStorageFor(['id' => 'free'])->storage())->toBe(TenantStorage::Pooled)
        ->and(decideStorageFor(['id' => 'paid'])->storage())->toBe(TenantStorage::Schema);
});

it('is a no-op on a tenant that already carries a storage marker — retry cannot flip storage', function () {
    config(['beam.tenancy.pooled.default_for_new_tenants' => true]);
    $tenant = Tenant::create(['id' => 'iso', 'name' => 'T', 'slug' => 'iso']);
    $tenant->markIsolatedDatabase(true)->save();

    (new DecideStorage($tenant))->handle();

    expect(Tenant::find('iso')->storage())->toBe(TenantStorage::Isolated)
        ->and(Tenant::find('iso')->pool)->toBeNull();
});

it('rejects an isolated request and an unknown request loudly', function () {
    expect(fn () => decideStorageFor(['id' => 'x', 'requested_storage' => 'isolated']))
        ->toThrow(InvalidArgumentException::class, 'Isolated');
    expect(fn () => decideStorageFor(['id' => 'y', 'requested_storage' => 'sharded']))
        ->toThrow(InvalidArgumentException::class, 'Unknown requested_storage');
});

it('rejects a resolver that does not implement the contract', function () {
    app()->bind('storage-resolver-wrong', fn () => new stdClass);
    config(['beam.tenancy.pooled.storage_resolver' => 'storage-resolver-wrong']);

    expect(fn () => decideStorageFor(['id' => 'z']))->toThrow(InvalidArgumentException::class, 'does not implement');
});
