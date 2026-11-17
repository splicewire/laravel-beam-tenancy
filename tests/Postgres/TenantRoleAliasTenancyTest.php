<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Tenancy\Features\TenantModelCatalog;
use Splicewire\Beam\Tenancy\Features\TenantRoleAlias;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias\ReadsDefaultModelJob;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Tenancy;

/**
 * config-sections ticket 15 (HIGH), the beam-tenancy PG lane: a tenant's role alias never leaks into the central config,
 * on schema, pooled and own-server tenants, under stancl's REAL bootstrappers, including two tenants' jobs on one worker.
 * Features as the flagship lists them (config/tenancy.php:138-140): stancl TenantConfig mapping
 * llm_config.preferred_model -> app.chat.default_model, then TenantModelCatalog, then TenantRoleAlias.
 *
 * Isolation: the harness drops `pool_*` schemas per test but not the `tenant_*` a schema tenant creates, and it binds
 * stancl's Tenancy singleton but not the Tenant contract its provider binds in real hosts. Both are done here (07 Step 1's
 * lessons).
 */
beforeEach(function () {
    foreach (DB::connection('central')->select("select schema_name from information_schema.schemata where schema_name like 'tenant\\_%'") as $row) {
        DB::connection('central')->statement('drop schema "'.$row->schema_name.'" cascade');
    }
    app()->bind(Stancl\Tenancy\Contracts\Tenant::class, fn ($app) => $app[Tenancy::class]->tenant);
    QueueTenancyBootstrapper::__constructStatic($this->app);

    config()->set('tenancy.bootstrappers', [DatabaseTenancyBootstrapper::class, CacheTenancyBootstrapper::class, QueueTenancyBootstrapper::class]);
    config()->set('cache.default', 'array');
    config()->set('app.chat.models', ['floor-model' => ['label' => 'Floor']]);
    config()->set('app.chat.default_model', 'floor-model');

    $this->previousMap = TenantConfig::$storageToConfigMap;
    TenantConfig::$storageToConfigMap = ['llm_config.preferred_model' => 'app.chat.default_model'];

    foreach ([TenantConfig::class, TenantModelCatalog::class, TenantRoleAlias::class] as $feature) {
        app($feature)->bootstrap(tenancy());
    }
});

afterEach(function () {
    // A skipped run (no PG_TEST_DATABASE) never reached beforeEach: nothing to restore.
    if (isset($this->previousMap)) {
        TenantConfig::$storageToConfigMap = $this->previousMap;
    }
});

/** The second Postgres server, as 07's lane names it (CS15_PG2_* falling back to CS07_PG2_*); null when none is named. */
function cs15PgServer2(): ?string
{
    $env = fn (string $key): string|false => getenv("CS15_PG2_{$key}") ?: getenv("CS07_PG2_{$key}");

    if (! $env('PORT') || ! $env('DATABASE')) {
        return null;
    }

    config()->set('database.connections.cs15_server2', array_merge(config('database.connections.central'), [
        'host' => $env('HOST') ?: '127.0.0.1',
        'port' => (int) $env('PORT'),
        'database' => $env('DATABASE'),
        'username' => $env('USERNAME') ?: 'postgres',
        'password' => $env('PASSWORD') ?: '',
    ]));
    $server2 = DB::connection('cs15_server2');
    foreach ($server2->select("select schema_name from information_schema.schemata where schema_name like 'pool\\_%' or schema_name like 'tenant\\_%'") as $row) {
        $server2->statement('drop schema "'.$row->schema_name.'" cascade');
    }
    expect($server2->selectOne('select inet_server_port() as p')->p)->not->toBe(DB::connection('central')->selectOne('select inet_server_port() as p')->p, 'the second server is a different Postgres instance');

    return 'cs15_server2';
}

/** @param  array<string, mixed>  $llm */
function cs15PgTenant(string $id, string $shape, array $llm): Tenant
{
    $connection = $shape === 'remote' ? cs15PgServer2() : null;
    $tenant = Tenant::create(['id' => $id, 'name' => ucfirst($id), 'slug' => $id, 'llm_config' => $llm] + ($connection === null ? [] : ['tenancy_db_connection' => $connection]));

    if ($shape === 'pooled') {
        $tenant->markPooled('default')->save();
    }

    $tenant->database()->makeCredentials();
    $tenant->database()->manager()->createDatabase($tenant);

    return Tenant::find($id);
}

function cs15PgRole(): array
{
    return ['preferred_model' => 'fast', 'provider' => 'acme-llm', 'models' => ['tenant-only-model' => ['label' => 'Tenant only']], 'roles' => ['acme-llm' => ['fast' => 'tenant-only-model']]];
}

function cs15PgInside(Tenant $tenant): string
{
    tenancy()->initialize($tenant);

    try {
        return (string) config('app.chat.default_model');
    } finally {
        tenancy()->end();
    }
}

dataset('cs15 storage', [
    'schema' => ['schema'],
    'pooled' => ['pooled'],
    'own server (isolated shape)' => ['remote'],
]);

it('keeps a role-picking tenant\'s alias out of the central config, and out of the next tenant\'s hop', function (string $shape) {
    if ($shape === 'remote' && cs15PgServer2() === null) {
        test()->markTestSkipped('CS15_PG2_* / CS07_PG2_* names no second Postgres server: the own-server shape needs one.');
    }

    $role = cs15PgTenant('alpha', $shape, cs15PgRole());
    $plain = cs15PgTenant('bravo', $shape === 'remote' ? 'schema' : $shape, ['provider' => 'acme-llm']);

    expect(cs15PgInside($role))->toBe('tenant-only-model', 'inside the hop the role expands after the catalog merge')
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central after the hop')
        ->and(config('app.chat.models'))->toBe(['floor-model' => ['label' => 'Floor']])
        ->and(cs15PgInside($plain))->toBe('floor-model', 'the next tenant never sees another tenant\'s role')
        ->and(config('app.chat.default_model'))->toBe('floor-model');
})->with('cs15 storage');

it('keeps two tenants\' jobs apart on one worker: the role tenant expands, the plain tenant and a central job see the floor', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'central', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('queue.failed.driver', 'null');
    Schema::connection('central')->create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::connection('central')->create('cs15_job_reads', function (Blueprint $table) {
        $table->id();
        $table->string('label');
        $table->string('tenant')->nullable();
        $table->string('default_model');
    });

    $alpha = cs15PgTenant('alpha', 'pooled', cs15PgRole());
    $bravo = cs15PgTenant('bravo', 'pooled', ['provider' => 'acme-llm']);

    // STATEMENT closures, not `fn () => X::dispatch()`: dispatch() returns a PendingDispatch that queues only in its
    // __destruct, and stancl's Tenant::run() holds an arrow function's return value across tenancy()->end()
    // (TenantRun.php:18-33), so the job would be queued from CENTRAL, untagged (07 Step 1's :243 finding).
    $alpha->run(function () {
        ReadsDefaultModelJob::dispatch('a-1');
    });
    $bravo->run(function () {
        ReadsDefaultModelJob::dispatch('b-1');
    });
    $alpha->run(function () {
        ReadsDefaultModelJob::dispatch('a-2');
    });
    ReadsDefaultModelJob::dispatch('central');
    $bravo->run(function () {
        ReadsDefaultModelJob::dispatch('b-2');
    });

    // ONE worker process (this one): A, B after A, A again, a central job, then B after the central job.
    for ($i = 0; $i < 5; $i++) {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
    }

    $reads = DB::connection('central')->table('cs15_job_reads')->orderBy('id')->get()->map(fn ($r) => [$r->label, $r->tenant, $r->default_model])->all();
    expect($reads)->toBe([
        ['a-1', 'alpha', 'tenant-only-model'],
        ['b-1', 'bravo', 'floor-model'],
        ['a-2', 'alpha', 'tenant-only-model'],
        ['central', null, 'floor-model'],
        ['b-2', 'bravo', 'floor-model'],
    ])->and(config('app.chat.default_model'))->toBe('floor-model', 'the worker ends on the central value');
});
