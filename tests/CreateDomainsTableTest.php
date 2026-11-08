<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Kept from DomainPurposeMigrationTest when migration-flatten 06 retired add_domain_purpose_to_domains_table: this case
// tests the create stub's own invariant (app-default purpose, one primary domain per tenant and purpose).
beforeEach(function () {
    Schema::dropIfExists('domains');
    Schema::dropIfExists('tenants');
});

afterEach(function () {
    Schema::dropIfExists('domains');
    Schema::dropIfExists('tenants');
});

it('publishes the purpose invariant for a fresh domains table', function () {
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
