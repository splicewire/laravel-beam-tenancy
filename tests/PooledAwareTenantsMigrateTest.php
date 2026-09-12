<?php

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Tenancy\Commands\PooledAwareTenantsMigrate;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Commands\Migrate;

/**
 * pooled-storage ticket 05 — `tenants:migrate` skips pooled tenants and never fans out on an empty list.
 */
it('replaces stancl\'s migrate command through the container, whatever the provider order', function () {
    expect(app(Migrate::class))->toBeInstanceOf(PooledAwareTenantsMigrate::class);
});

it('skips a pooled tenant by name and does not fan out to every tenant when nothing is left', function () {
    // The harness boots no stancl provider, so the command is registered here the way stancl's
    // provider would — through the container, which is where the extension applies.
    Artisan::registerCommand(app(Migrate::class));
    config(['tenancy.migration_parameters' => ['--force' => true]]);
    Tenant::create(['id' => 'small', 'name' => 'Small', 'slug' => 'small'])->markPooled('default')->save();
    // A second, schema tenant that MUST NOT be touched: the harness registers no database manager, so
    // any attempt to initialize tenancy on it throws — which is exactly what an empty-list fan-out
    // would do, and the assertion that it does not.
    Tenant::create(['id' => 'big', 'name' => 'Big', 'slug' => 'big']);

    $this->artisan('tenants:migrate', ['--tenants' => ['small']])
        ->expectsOutputToContain('small — pooled (pool_default); skipped')
        ->assertSuccessful();
});

it('refuses to roll back or wipe a pooled tenant — the schema is the whole pool\'s', function () {
    Artisan::registerCommand(app(Stancl\Tenancy\Commands\Rollback::class));
    Artisan::registerCommand(app(Stancl\Tenancy\Commands\MigrateFresh::class));
    config(['tenancy.migration_parameters' => ['--force' => true]]);
    Tenant::create(['id' => 'small', 'name' => 'Small', 'slug' => 'small'])->markPooled('default')->save();

    expect(app(Stancl\Tenancy\Commands\Rollback::class))->toBeInstanceOf(Splicewire\Beam\Tenancy\Commands\PooledAwareTenantsRollback::class)
        ->and(app(Stancl\Tenancy\Commands\MigrateFresh::class))->toBeInstanceOf(Splicewire\Beam\Tenancy\Commands\PooledAwareTenantsMigrateFresh::class);

    $this->artisan('tenants:rollback', ['--tenants' => ['small']])
        ->expectsOutputToContain('Refusing to roll back pooled tenant(s)')
        ->assertFailed();
    $this->artisan('tenants:migrate-fresh', ['--tenants' => ['small']])
        ->expectsOutputToContain('Refusing to wipe and re-migrate pooled tenant(s)')
        ->assertFailed();
});
