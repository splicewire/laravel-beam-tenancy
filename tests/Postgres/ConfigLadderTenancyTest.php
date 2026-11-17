<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tenancy\Commands\PoolsMove;
use Splicewire\Beam\Tenancy\ConfigLadderTenancyBootstrapper;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder\LadderSection;
use Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder\ReadsLadderConfigJob;
use Splicewire\Beam\Tenancy\Tests\Postgres\PostgresTestCase;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

/**
 * config-sections ticket 07 (beam-tenancy half): a tenant override applies inside the hop and reverts, under stancl's
 * REAL bootstrappers on Postgres, with ConfigLadderTenancyBootstrapper listed after the database and cache switch.
 *
 * Storage shapes (the AC's "schema, pooled and isolated"): a SCHEMA tenant (its own `tenant_<id>` schema), a POOLED
 * tenant (rows kept apart by row-level security in a shared pool schema), and a tenant whose rows live in a DIFFERENT
 * Postgres database (the harness's second server). The last is the isolated shape this harness can stand up: a true
 * Isolated Database (GCP / customer-supplied) connects to another host over verify-full TLS, which no local harness has;
 * 07 changes nothing on that path, and the receipt names it unproven here (lead 00:57Z, put to root as an AC question).
 * Relocation: the real `pools:move` across servers.
 *
 * Every override is written through laravel-beam's store inside the tenant's own hop, the path the `beam-config` ops take.
 */
beforeEach(function () {
    // stancl's queue listeners (the harness boots no stancl provider).
    QueueTenancyBootstrapper::__constructStatic($this->app);

    // The shared stubs on central, as the host's central migrate pass runs them.
    foreach (['create_beam_versions_table', 'create_beam_config_overrides_table', 'create_beam_config_locks_table'] as $stub) {
        (require __DIR__.'/../../vendor/splicewire/laravel-beam/database/migrations/shared/'.$stub.'.php.stub')->up();
    }

    Schema::connection('central')->create('cs07_job_reads', function (Blueprint $table) {
        $table->id();
        $table->string('label');
        $table->string('tenant')->nullable();
        $table->string('review');
        $table->string('theme');
    });

    config()->set('cs07', ['review' => false, 'theme' => 'light', 'group' => ['review' => false, 'label' => 'base']]);
    app()->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
    app(ConfigSectionRegistry::class)->section(LadderSection::class, owner: 'host');
    foreach ([ConfigOverrideStore::class, ConfigOverlay::class, ConfigTenantOverlay::class] as $singleton) {
        app()->forgetInstance($singleton);
    }
});

/**
 * The SECOND Postgres server (root ruling 3fe00fb4): a separate PG instance named by `CS07_PG2_HOST` / `CS07_PG2_PORT` /
 * `CS07_PG2_DATABASE` (a throwaway database that already exists) / `CS07_PG2_USERNAME` / `CS07_PG2_PASSWORD`. Registered as
 * the connection `cs07_server2` and as the pool `server2`, with the harness's non-owner role created there. Null when the
 * environment names none, and the cases that need it are skipped, saying why.
 */
function cs07Server2(): ?string
{
    if (! getenv('CS07_PG2_PORT') || ! getenv('CS07_PG2_DATABASE')) {
        return null;
    }

    config()->set('database.connections.cs07_server2', array_merge(config('database.connections.central'), [
        'host' => getenv('CS07_PG2_HOST') ?: '127.0.0.1',
        'port' => (int) getenv('CS07_PG2_PORT'),
        'database' => getenv('CS07_PG2_DATABASE'),
        'username' => getenv('CS07_PG2_USERNAME') ?: 'postgres',
        'password' => getenv('CS07_PG2_PASSWORD') ?: '',
    ]));
    config()->set('beam.tenancy.pooled.pools.server2', ['connection' => 'cs07_server2']);

    $server2 = DB::connection('cs07_server2');
    foreach ($server2->select("select schema_name from information_schema.schemata where schema_name like 'pool\\_%' or schema_name like 'tenant\\_%'") as $row) {
        $server2->statement('drop schema "'.$row->schema_name.'" cascade');
    }
    $server2->statement("do $$ begin if not exists (select 1 from pg_roles where rolname = '".PostgresTestCase::RLS_ROLE."') then create role ".PostgresTestCase::RLS_ROLE.' login; end if; end $$');
    expect($server2->selectOne('select inet_server_port() as p')->p)->not->toBe(DB::connection('central')->selectOne('select inet_server_port() as p')->p, 'the second server is a different Postgres instance');

    return 'cs07_server2';
}

/** The bootstrapper order hosts list: the database and cache switch, then the ladder, then the queue. */
function cs07Bootstrappers(): void
{
    config()->set('tenancy.bootstrappers', [
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        ConfigLadderTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ]);
    config()->set('cache.default', 'array');
    config()->set('tenancy.migration_parameters.--path', [
        ...(array) config('tenancy.migration_parameters.--path'),
        realpath(__DIR__.'/../Fixtures/config-migrations'),
    ]);
}

/** Migrate the shared config stubs into a schema tenant's own schema (stancl's MigrateDatabase job, by hand). */
function cs07MigrateTenant(Tenant $tenant): void
{
    tenancy()->initialize($tenant);
    Artisan::call('migrate', ['--path' => [realpath(__DIR__.'/../Fixtures/config-migrations')], '--realpath' => true, '--database' => 'tenant', '--force' => true]);
    tenancy()->end();
}

function cs07Schema(string $id, ?string $connection = null): Tenant
{
    $tenant = Tenant::create(['id' => $id, 'name' => ucfirst($id), 'slug' => $id] + ($connection === null ? [] : ['tenancy_db_connection' => $connection]));
    $tenant->database()->makeCredentials();
    $tenant->database()->manager()->createDatabase($tenant);
    $tenant = Tenant::find($id);
    cs07MigrateTenant($tenant);

    return $tenant;
}

function cs07Pooled(string $id, string $pool = 'default'): Tenant
{
    $tenant = Tenant::create(['id' => $id, 'name' => ucfirst($id), 'slug' => $id]);
    $tenant->markPooled($pool)->save();
    $tenant->database()->makeCredentials();
    $tenant->database()->manager()->createDatabase($tenant);

    return Tenant::find($id);
}

/** Inside `$tenant`'s own hop: save and publish ops on the tenant tier. */
function cs07TenantPublish(Tenant $tenant, array $ops): void
{
    tenancy()->initialize($tenant);

    try {
        $store = app(ConfigOverrideStore::class);
        expect($store->tier())->toBe(ConfigTier::Tenant);
        $store->saveDraft('cs07', $ops);
        $store->publish('cs07');
    } finally {
        tenancy()->end();
    }
}

function cs07OperatorPublish(array $ops): void
{
    $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator);
    $store->saveDraft('cs07', $ops);
    $store->publish('cs07');
}

/** @return array{review: mixed, theme: mixed} what `config()` holds inside `$tenant`'s hop */
function cs07Inside(Tenant $tenant): array
{
    tenancy()->initialize($tenant);

    try {
        return ['review' => config('cs07.review'), 'theme' => config('cs07.theme')];
    } finally {
        tenancy()->end();
    }
}

dataset('cs07 storage', [
    'schema' => [fn () => [cs07Schema('alpha'), cs07Schema('bravo')], 'schema'],
    'pooled' => [fn () => [cs07Pooled('alpha'), cs07Pooled('bravo')], 'pooled'],
    'own server (isolated shape)' => [function () {
        $server2 = cs07Server2();

        if ($server2 === null) {
            test()->markTestSkipped('CS07_PG2_* names no second Postgres server: the isolated shape (a tenant on a different server, root ruling 3fe00fb4) needs one.');
        }

        return [cs07Schema('alpha', $server2), cs07Schema('bravo')];
    }, 'remote'],
]);

it('applies a tenant override inside that tenant\'s hop only, and reverts to the central effective value', function (Closure $make, string $shape) {
    cs07Bootstrappers();
    [$alpha, $bravo] = $make();

    cs07OperatorPublish([['pointer' => '/theme', 'op' => 'set', 'value' => 'dark']]);
    cs07TenantPublish($alpha, [['pointer' => '/review', 'op' => 'set', 'value' => true], ['pointer' => '/theme', 'op' => 'set', 'value' => 'pink']]);

    expect(cs07Inside($alpha))->toBe(['review' => true, 'theme' => 'pink'])
        ->and(cs07Inside($bravo))->toBe(['review' => false, 'theme' => 'dark'], "bravo inherits the operator's value, never alpha's")
        ->and(config('cs07.review'))->toBeFalse('the central context never sees alpha')
        ->and(config('cs07.theme'))->toBe('dark', "revert lands on the operator's override, not the package default")
        ->and(cs07Inside($alpha))->toBe(['review' => true, 'theme' => 'pink'], 'a second hop re-applies');

    // The tenant's rows are in its own storage, and nowhere central.
    expect(DB::connection('central')->table(Beam::table('config_scopes'))->where('section', 'cs07')->count())->toBe(1, "central holds the operator's scope only");
    tenancy()->initialize($alpha);
    expect(DB::table(Beam::table('config_scopes'))->where('section', 'cs07')->count())->toBe(1)
        ->and(DB::table(Beam::table('versions'))->count())->toBeGreaterThan(0, "alpha's history is in its workspace");
    if ($shape === 'remote') {
        expect(DB::connection('tenant')->selectOne('select inet_server_port() as p')->p)->toBe((int) getenv('CS07_PG2_PORT'), "alpha's rows are on the second server");
    }
    tenancy()->end();
    tenancy()->initialize($bravo);
    expect(DB::table(Beam::table('config_scopes'))->where('section', 'cs07')->count())->toBe(0, 'bravo holds none of alpha\'s rows');
    tenancy()->end();
})->with('cs07 storage');

it('keeps two tenants\' jobs apart on one worker: each runs with its own tier, and a central job with none', function () {
    cs07Bootstrappers();
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

    $alpha = cs07Pooled('alpha');
    $bravo = cs07Pooled('bravo');
    cs07OperatorPublish([['pointer' => '/theme', 'op' => 'set', 'value' => 'dark']]);
    cs07TenantPublish($alpha, [['pointer' => '/review', 'op' => 'set', 'value' => true], ['pointer' => '/theme', 'op' => 'set', 'value' => 'pink']]);

    $alpha->run(fn () => ReadsLadderConfigJob::dispatch('a-1'));
    $bravo->run(fn () => ReadsLadderConfigJob::dispatch('b-1'));
    $alpha->run(fn () => ReadsLadderConfigJob::dispatch('a-2'));
    ReadsLadderConfigJob::dispatch('central');

    // ONE worker process (this one), four jobs in order: A, B after A, A again, then a central job.
    for ($i = 0; $i < 4; $i++) {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
    }

    $reads = DB::connection('central')->table('cs07_job_reads')->orderBy('id')->get()->map(fn ($r) => [$r->label, $r->tenant, $r->review, $r->theme])->all();
    expect($reads)->toBe([
        ['a-1', 'alpha', 'true', 'pink'],
        ['b-1', 'bravo', 'false', 'dark'],
        ['a-2', 'alpha', 'true', 'pink'],
        ['central', null, 'false', 'dark'],
    ])
        ->and(config('cs07.review'))->toBeFalse('the worker ends on the central values')
        ->and(config('cs07.theme'))->toBe('dark');
});

it('keeps a tenant\'s overrides when it is relocated to a pool on another server', function () {
    cs07Bootstrappers();
    // The second Postgres SERVER when the environment names one (root ruling 3fe00fb4); otherwise the harness's
    // second database on this server, which proves the move but not a server boundary.
    $server2 = cs07Server2();
    $target = $server2 === null ? 'remote' : 'server2';

    $alpha = cs07Pooled('alpha', 'default');
    cs07Pooled('bravo', 'default');
    cs07TenantPublish($alpha, [['pointer' => '/review', 'op' => 'set', 'value' => true]]);
    expect(cs07Inside($alpha)['review'])->toBeTrue();

    Artisan::registerCommand(app(PoolsMove::class));
    $this->artisan('splicewire:beam:tenancy:pools:move', ['tenant' => 'alpha', 'pool' => $target])->assertSuccessful();

    $moved = Tenant::find('alpha');
    expect($moved->pool)->toBe($target)
        ->and(cs07Inside($moved))->toBe(['review' => true, 'theme' => 'light'], 'the override moved with the tenant');

    tenancy()->initialize($moved);
    expect(app(ConfigOverrideStore::class)->versionsOf('cs07'))->not->toBeEmpty('its history moved too');
    if ($server2 !== null) {
        expect(DB::connection('tenant')->selectOne('select inet_server_port() as p')->p)->toBe((int) getenv('CS07_PG2_PORT'), 'the tenant now reads from the second server');
    } else {
        expect(DB::connection('tenant')->selectOne('select current_database() as d')->d)->toBe(PostgresTestCase::remoteDatabase());
    }
    tenancy()->end();
});

it('lets an operator lock stop a published tenant override at the next hop, and refuses the tenant write with the reason', function () {
    cs07Bootstrappers();
    $alpha = cs07Schema('alpha');
    cs07TenantPublish($alpha, [['pointer' => '/review', 'op' => 'set', 'value' => true]]);
    expect(cs07Inside($alpha)['review'])->toBeTrue();

    app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->lock('cs07', '/review', 'Review policy is set by the operator.');

    expect(cs07Inside($alpha)['review'])->toBeFalse('the lock takes the key away at the next hop');

    tenancy()->initialize($alpha);
    try {
        expect(fn () => app(ConfigOverrideStore::class)->saveDraft('cs07', [['pointer' => '/review', 'op' => 'set', 'value' => true]]))
            ->toThrow(Splicewire\Beam\Config\Overrides\ConfigOverrideRefusal::class, '/review is locked by the operator: Review policy is set by the operator.');
    } finally {
        tenancy()->end();
    }
});

/** review-r1 (07 Spec): a lock holds at any depth, so a locked child inside a published tenant PARENT reads central. */
it('holds an operator lock on a child against a published tenant parent at the next hop', function () {
    cs07Bootstrappers();
    $alpha = cs07Pooled('alpha');
    cs07TenantPublish($alpha, [['pointer' => '/group', 'op' => 'set', 'value' => ['review' => true, 'label' => 'Tenant label']]]);

    tenancy()->initialize($alpha);
    expect(config('cs07.group'))->toBe(['review' => true, 'label' => 'Tenant label']);
    tenancy()->end();

    app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->lock('cs07', '/group/review', 'Review policy is set by the operator.');

    tenancy()->initialize($alpha);
    try {
        expect(config('cs07.group'))->toBe(['review' => false, 'label' => 'Tenant label'], 'the locked child is central, its sibling the tenant\'s');
    } finally {
        tenancy()->end();
    }
    expect(config('cs07.group'))->toBe(['review' => false, 'label' => 'base']);
});
