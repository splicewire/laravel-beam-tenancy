<?php

use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantStorage;

/**
 * pooled-storage ticket 04 — the storage state is DERIVED from markers, one source of truth each.
 */
it('is schema storage when no marker is set', function () {
    $tenant = new Tenant(['id' => 'plain']);

    expect($tenant->storage())->toBe(TenantStorage::Schema)
        ->and($tenant->isPooled())->toBeFalse();
});

it('is pooled storage once marked with a pool, and clears back to schema with null', function () {
    $tenant = new Tenant(['id' => 'small']);
    $tenant->markPooled('default');

    expect($tenant->storage())->toBe(TenantStorage::Pooled)
        ->and($tenant->isPooled())->toBeTrue()
        ->and($tenant->pool)->toBe('default');

    $tenant->markPooled(null);

    expect($tenant->storage())->toBe(TenantStorage::Schema);
});

it('lets isolated win over pooled — the markers are written in that order in a tenant\'s life', function () {
    $tenant = new Tenant(['id' => 'grown']);
    $tenant->markPooled('default')->markIsolatedDatabase(true);

    expect($tenant->storage())->toBe(TenantStorage::Isolated);
});

it('persists the pool marker in the data column and round-trips it', function () {
    Tenant::create(['id' => 'acme', 'name' => 'Acme', 'slug' => 'acme']);
    Tenant::find('acme')->markPooled('default')->save();

    expect(Tenant::find('acme')->storage())->toBe(TenantStorage::Pooled);
});

it('rides session settings through stancl\'s tenancy_db_* merge as `session_settings`', function () {
    // The mechanism the pooled connection depends on (ticket 05): stancl's DatabaseConfig::tenantConfig()
    // strips `tenancy_db_` off every internal so named and merges the remainder onto the template
    // connection. An array value must survive the data column round-trip, un-encrypted.
    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme', 'slug' => 'acme']);
    $tenant->setInternal('db_session_settings', ['app.tenant_id' => 'acme']);
    $tenant->save();

    $fresh = Tenant::find('acme');

    expect($fresh->tenancy_db_session_settings)->toBe(['app.tenant_id' => 'acme'])
        ->and($fresh->database()->tenantConfig())->toMatchArray(['session_settings' => ['app.tenant_id' => 'acme']]);
});

it('names exactly two creatable states — isolated only ever arrives by migration', function () {
    expect(TenantStorage::creatable())->toBe([TenantStorage::Pooled, TenantStorage::Schema])
        ->and(TenantStorage::creatableValues())->toBe(['pooled', 'schema']);
});
