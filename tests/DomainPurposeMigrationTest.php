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
        ['domain' => 'acme.app.test', 'tenant_id' => 'acme', 'purpose' => 'app', 'is_primary' => true],
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

it('publishes the same purpose invariant for a fresh domains table', function () {
    Schema::dropIfExists('domains');
    Schema::create('tenants', function (Blueprint $table) {
        $table->string('id')->primary();
    });

    require_once __DIR__.'/../database/migrations/create_domains_table.php.stub';
    (new CreateDomainsTable)->up();

    DB::table('tenants')->insert(['id' => 'acme']);
    DB::table('domains')->insert([
        'domain' => 'acme.app.test', 'tenant_id' => 'acme', 'is_primary' => true,
    ]);
    DB::table('domains')->insert([
        'domain' => 'acme.site.test', 'tenant_id' => 'acme', 'purpose' => 'site', 'is_primary' => true,
    ]);

    expect(DB::table('domains')->where('tenant_id', 'acme')->count())->toBe(2)
        ->and(DB::table('domains')->where('domain', 'acme.app.test')->value('purpose'))->toBe('app');

    expect(fn () => DB::table('domains')->insert([
        'domain' => 'duplicate.app.test', 'tenant_id' => 'acme', 'is_primary' => true,
    ]))->toThrow(QueryException::class);
});
