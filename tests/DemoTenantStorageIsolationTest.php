<?php

use Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;

beforeEach(function () {
    config([
        'beam.tenancy.demo.tenant.slug' => 'beam_demo',
        'tenancy.database.template_tenant_connection' => 'demo_pg',
        'database.connections.demo_pg' => ['driver' => 'pgsql', 'host' => 'fixture-server', 'database' => 'fixture_host'],
        'tenancy.database.managers.pgsql' => PostgreSQLDatabaseManager::class,
    ]);
});

function demoStorageTenant(): Tenant
{
    return (new class extends DemoTenantSeeder
    {
        public function resolveDemoTenant(): Tenant
        {
            return $this->tenant();
        }
    })->resolveDemoTenant();
}

it('gives independent fresh installs different demo database names on one server', function () {
    $first = demoStorageTenant();
    $name = $first->database()->getName();
    expect(demoStorageTenant()->database()->getName())->toBe($name);

    // Empty central tenant store models another fresh install; no tenant storage is provisioned.
    $first->deleteQuietly();
    $second = demoStorageTenant();
    $manager = new PostgreSQLDatabaseManager;
    $base = ['host' => 'fixture-server', 'database' => 'fixture_host'];
    expect($manager->makeConnectionConfig($base, $second->database()->getName()))
        ->not->toBe($manager->makeConnectionConfig($base, $name));
    expect($second->getTenantKey())->toBe('beam_demo')
        ->and($second->getInternal('db_name'))->not->toBeNull()
        ->and(strlen($second->database()->getName()))->toBeLessThanOrEqual(63)
        ->and($second->database()->getName())->toMatch('/^[a-z0-9_]+$/');
});

it('keeps an existing legacy demo tenant and its derived storage name', function () {
    $tenant = Tenant::create(['id' => 'beam_demo', 'name' => 'Existing demo']);
    $name = $tenant->database()->getName();
    expect(demoStorageTenant()->database()->getName())->toBe($name)
        ->and(demoStorageTenant()->getInternal('db_name'))->toBeNull()
        ->and(Tenant::count())->toBe(1);
});

it('keeps an existing explicitly provisioned demo database', function () {
    $tenant = Tenant::create(['id' => 'beam_demo', 'name' => 'Existing demo']);
    $tenant->setInternal('db_name', 'existing_demo_database');
    $tenant->save();
    expect(demoStorageTenant()->database()->getName())->toBe('existing_demo_database');
});

it('bounds a new demo database identifier even with a long configured prefix', function () {
    config(['tenancy.database.prefix' => str_repeat('prefix_', 10)]);
    expect(strlen(demoStorageTenant()->database()->getName()))->toBe(63);
});

it('leaves schema storage names scoped by the host database', function () {
    config(['tenancy.database.managers.pgsql' => Splicewire\Beam\Tenancy\PostgreSQLSchemaManager::class]);
    $tenant = demoStorageTenant();
    expect($tenant->getInternal('db_name'))->toBeNull()
        ->and($tenant->database()->getName())->toBe('tenant_beam_demo');
});
