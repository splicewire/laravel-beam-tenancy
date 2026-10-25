<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Tenancy\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Schema::dropIfExists('domains');
    Schema::dropIfExists('tenants');
    Schema::create('domains', function (Blueprint $table) {
        $table->increments('id');
        $table->string('domain')->unique();
        $table->string('tenant_id');
        $table->boolean('is_primary')->default(false);
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('domains');
    Schema::dropIfExists('tenants');
});

it('adds app-default purpose and permits one primary domain per purpose', function () {
    $migration = require __DIR__.'/../database/migrations/add_domain_purpose_to_domains_table.php.stub';
    $migration->up();

    DB::table('domains')->insert([
        ['domain' => 'acme', 'tenant_id' => 'acme', 'purpose' => 'app', 'is_primary' => true],
        ['domain' => 'acme.site.test', 'tenant_id' => 'acme', 'purpose' => 'site', 'is_primary' => true],
        ['domain' => 'old.example', 'tenant_id' => 'acme', 'purpose' => 'app', 'is_primary' => false],
    ]);

    expect(Schema::hasColumn('domains', 'purpose'))
        ->toBeTrue()
        ->and(DB::table('domains')->where('domain', 'old.example')->value('purpose'))->toBe('app')
        ->and(DB::table('domains')->count())->toBe(3);

    expect(fn () => DB::table('domains')->insert([
        'domain' => 'duplicate.app.test', 'tenant_id' => 'acme', 'purpose' => 'app', 'is_primary' => true,
    ]))->toThrow(QueryException::class);
});

it('refuses duplicate existing primary domains without changing their flags', function () {
    DB::table('domains')->insert([
        ['domain' => 'acme.app.test', 'tenant_id' => 'acme', 'is_primary' => true],
        ['domain' => 'acme.other.test', 'tenant_id' => 'acme', 'is_primary' => true],
    ]);

    $migration = require __DIR__.'/../database/migrations/add_domain_purpose_to_domains_table.php.stub';

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'acme')
        ->and(DB::table('domains')->where('is_primary', true)->count())->toBe(2)
        ->and(Schema::hasIndex('domains', 'domains_tenant_primary_purpose_unique'))->toBeFalse();
});

it('publishes the same purpose invariant for a fresh domains table', function () {
    Schema::dropIfExists('domains');
    Schema::create('tenants', function (Blueprint $table) {
        $table->string('id')->primary();
    });

    require_once __DIR__.'/../database/migrations/create_domains_table.php.stub';
    (new CreateDomainsTable)->up();

    DB::table('tenants')->insert(['id' => 'acme']);
    DB::table('domains')->insert([
        'domain' => 'acme', 'tenant_id' => 'acme', 'is_primary' => true,
    ]);
    DB::table('domains')->insert([
        'domain' => 'acme.site.test', 'tenant_id' => 'acme', 'purpose' => 'site', 'is_primary' => true,
    ]);

    expect(DB::table('domains')->where('tenant_id', 'acme')->count())->toBe(2)
        ->and(DB::table('domains')->where('domain', 'acme')->value('purpose'))->toBe('app');

    expect(fn () => DB::table('domains')->insert([
        'domain' => 'duplicate.app.test', 'tenant_id' => 'acme', 'is_primary' => true,
    ]))->toThrow(QueryException::class);
});

it('proves duplicate refusal and generated primary-purpose uniqueness on isolated PostgreSQL', function () {
    $database = getenv('EP32_PGSQL_TEST_DATABASE');

    if (! is_string($database) || ! preg_match('/^test_[a-z0-9_]{1,54}$/', $database)) {
        test()->markTestSkipped('Set EP32_PGSQL_TEST_DATABASE to an isolated test_* database.');
    }

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => getenv('EP32_PGSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('EP32_PGSQL_PORT') ?: '5432',
            'database' => $database,
            'username' => getenv('EP32_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('EP32_PGSQL_PASSWORD') ?: '',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],
    ]);
    DB::purge('pgsql');
    DB::setDefaultConnection('pgsql');

    // The connection is guarded by the test_* name above; all DDL below is confined to it.
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe($database);

    $createLegacyDomains = function (): void {
        Schema::create('domains', function (Blueprint $table) {
            $table->increments('id');
            $table->string('domain')->unique();
            $table->string('tenant_id');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    };

    $createLegacyDomains();
    DB::table('domains')->insert([
        ['domain' => 'acme-one.test', 'tenant_id' => 'acme', 'is_primary' => true],
        ['domain' => 'acme-two.test', 'tenant_id' => 'acme', 'is_primary' => true],
    ]);

    $migration = require __DIR__.'/../database/migrations/add_domain_purpose_to_domains_table.php.stub';

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'acme')
        ->and(Schema::hasColumn('domains', 'purpose'))->toBeFalse()
        ->and(DB::table('domains')->where('is_primary', true)->count())->toBe(2);

    Schema::dropIfExists('domains');
    $createLegacyDomains();
    $migration = require __DIR__.'/../database/migrations/add_domain_purpose_to_domains_table.php.stub';
    $migration->up();

    DB::table('domains')->insert([
        ['domain' => 'acme', 'tenant_id' => 'acme', 'purpose' => 'app', 'is_primary' => true],
        ['domain' => 'acme.site.test', 'tenant_id' => 'acme', 'purpose' => 'site', 'is_primary' => true],
    ]);

    expect(fn () => DB::table('domains')->insert([
        'domain' => 'duplicate.app.test', 'tenant_id' => 'acme', 'purpose' => 'app', 'is_primary' => true,
    ]))->toThrow(QueryException::class);
});
