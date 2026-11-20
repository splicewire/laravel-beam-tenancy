<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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
use Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder\LegacySecretSection;
use Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder\ReadsLadderConfigJob;
use Splicewire\Beam\Tenancy\Tests\Postgres\PostgresTestCase;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Tenancy;

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
    // Isolation (TOTS 07 Step 1): the harness drops `pool_*` schemas per test (PostgresTestCase::defineDatabaseMigrations)
    // but not the `tenant_*` schemas a SCHEMA tenant creates, and the database reset deletes the tenants' ROWS only, so the
    // next test's cs07Schema('alpha') hit "duplicate schema tenant_alpha". Drop them on central first, as server2's are.
    foreach (DB::connection('central')->select("select schema_name from information_schema.schemata where schema_name like 'tenant\\_%'") as $row) {
        DB::connection('central')->statement('drop schema "'.$row->schema_name.'" cascade');
    }

    // stancl's provider binds the Tenant contract to the current tenant; this harness boots no stancl provider and
    // wireTenancyEvents() binds only the Tenancy singleton. Unbound, stancl's tenant() helper answers null everywhere: the
    // QueueTenancyBootstrapper's payload (tenant()->getTenantKey()) cannot tag a job with its tenant and a job cannot see
    // its tenant, which is what a real host never does (TOTS 07 Step 1, the two-tenant worker case).
    app()->bind(Stancl\Tenancy\Contracts\Tenant::class, fn ($app) => $app[Tenancy::class]->tenant);

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

    // A STATEMENT closure, not `fn () => X::dispatch()`: dispatch() returns a PendingDispatch that queues the job only
    // in its __destruct. An arrow function RETURNS it, and stancl's Tenant::run() holds that return value across
    // tenancy()->end() (TenantRun.php:18-33), so the job would be queued from CENTRAL, untagged (TOTS 07 Step 1, :243).
    // As a statement, it is destroyed inside the tenant's hop, as a real request's dispatch is.
    $alpha->run(function () {
        ReadsLadderConfigJob::dispatch('a-1');
    });
    $bravo->run(function () {
        ReadsLadderConfigJob::dispatch('b-1');
    });
    $alpha->run(function () {
        ReadsLadderConfigJob::dispatch('a-2');
    });
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
            // ConfigOverrideRefusal::because() answers a ValidationException (the 422 an op returns); the refusal class is
            // a factory, not a Throwable.
            ->toThrow(ValidationException::class, '/review is locked by the operator: Review policy is set by the operator.');
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
        // Values, not key order: the cut child is inherited back AFTER the tenant's kept sibling (TOTS 07 Step 1).
        expect(config('cs07.group'))->toEqual(['review' => false, 'label' => 'Tenant label'], 'the locked child is central, its sibling the tenant\'s');
    } finally {
        tenancy()->end();
    }
    expect(config('cs07.group'))->toBe(['review' => false, 'label' => 'base']);
});

/** Row count and an md5 of every row of central's store and version tables, in a stable order. */
function cs07CentralFingerprint(): array
{
    $fingerprint = [];

    foreach (['config_scopes', 'config_overrides', 'versions'] as $name) {
        $rows = DB::connection('central')->select('select md5(t::text) as h from public.'.Beam::table($name).' t order by 1');
        $fingerprint[$name] = [count($rows), md5(implode(',', array_map(fn (object $row): string => $row->h, $rows)))];
    }

    return $fingerprint;
}

/**
 * root ab22fd59 (laravel-beam's tenant-target guard): the REAL hazard is Postgres's `<tenant schema>,public` search path. A
 * schema tenant whose own schema lacks the store's table (a host that ran the central migrate but not tenants:migrate)
 * would resolve the store's unqualified queries to central's `public` OPERATOR table. The guard refuses the write, and
 * central is byte-untouched.
 */
it('refuses a tenant write that would fall through ,public into central\'s operator table, leaving central byte-untouched', function () {
    cs07Bootstrappers();
    cs07OperatorPublish([['pointer' => '/theme', 'op' => 'set', 'value' => 'dark']]);

    // A schema tenant WITHOUT the shared config migration in its own schema.
    $alpha = Tenant::create(['id' => 'alpha', 'name' => 'Alpha', 'slug' => 'alpha']);
    $alpha->database()->makeCredentials();
    $alpha->database()->manager()->createDatabase($alpha);
    $alpha = Tenant::find('alpha');
    $before = cs07CentralFingerprint();

    tenancy()->initialize($alpha);
    try {
        // The fall-through is real: the tenant connection's unqualified read reaches central's operator rows.
        expect(DB::table(Beam::table('config_scopes'))->where('section', 'cs07')->count())->toBe(1, 'the tenant connection sees central\'s row through ,public');

        $store = app(ConfigOverrideStore::class);
        expect($store->tier())->toBe(ConfigTier::Tenant);

        // Every WRITE is refused (review 05:34Z: discardDraft and restore too).
        foreach ([
            'saveDraft' => fn () => $store->saveDraft('cs07', [['pointer' => '/review', 'op' => 'set', 'value' => true]]),
            'publish' => fn () => $store->publish('cs07'),
            'discardDraft' => fn () => $store->discardDraft('cs07'),
            'restore' => fn () => $store->restore('cs07', 'any'),
        ] as $method => $write) {
            expect($write)->toThrow(ValidationException::class, "lacks the store's tables `".Beam::table('config_scopes').'`');
        }

        // And every READ finds nothing, never central's operator scope, history or layers as the tenant's own.
        expect($store->scope('cs07'))->toBeNull('the tenant tier finds no scope through ,public')
            ->and($store->versionsOf('cs07'))->toBe([], 'nor the operator\'s history')
            ->and($store->publishedLayers())->toBe([])
            ->and($store->publishedToken())->toBe('none');
    } finally {
        tenancy()->end();
    }

    expect(cs07CentralFingerprint())->toBe($before, 'central\'s store is byte-untouched')
        ->and(config('cs07.review'))->toBeFalse('central reads no tenant value');

    // The same tenant, once migrated, writes into its OWN schema.
    cs07MigrateTenant($alpha);
    cs07TenantPublish($alpha, [['pointer' => '/review', 'op' => 'set', 'value' => true]]);
    expect(cs07Inside($alpha)['review'])->toBeTrue()
        ->and(cs07CentralFingerprint())->toBe($before, 'and its write still never touched central');
});

/**
 * Review 05:40Z: the same server reached a SECOND way. A pool configured onto central's own server through another host
 * spelling, whose pool schema is missing (a failed provision), puts the tenant session in central's database with
 * current_schema() falling back to `public`. Its server ADDRESS may differ from central's (localhost as ::1, a socket),
 * which an address comparison would miss. The guard compares database + schema only, and refuses.
 */
it('refuses a tenant whose session lands in central\'s database and public schema through a second host spelling', function () {
    cs07Bootstrappers();
    cs07OperatorPublish([['pointer' => '/theme', 'op' => 'set', 'value' => 'dark']]);

    $central = config('database.connections.central');
    config()->set('database.connections.cs07_alias', array_merge($central, ['host' => ($central['host'] ?? '') === 'localhost' ? '127.0.0.1' : 'localhost']));
    config()->set('beam.tenancy.pooled.pools.alias', ['connection' => 'cs07_alias']);

    $alpha = cs07Pooled('alpha', 'alias');
    DB::connection('central')->statement('drop schema if exists "'.$alpha->poolSchema().'" cascade');
    $before = cs07CentralFingerprint();
    $centralAddress = DB::connection('central')->selectOne('select inet_server_addr()::text as a')->a;

    tenancy()->initialize($alpha);
    try {
        $session = DB::selectOne('select current_database() as d, current_schema() as s, inet_server_addr()::text as a');
        expect([$session->d, $session->s])->toBe([DB::connection('central')->selectOne('select current_database() as d')->d, 'public'], 'the tenant session sits in central\'s database and public schema');
        fwrite(STDERR, "\n[cs07-alias] central address ".var_export($centralAddress, true).', tenant address '.var_export($session->a, true).($session->a === $centralAddress ? ' (same on this host; an address check would also have caught it)' : ' (DIFFERENT: an address comparison would have missed it)')."\n");

        $store = app(ConfigOverrideStore::class);
        expect(fn () => $store->saveDraft('cs07', [['pointer' => '/review', 'op' => 'set', 'value' => true]]))
            ->toThrow(ValidationException::class, "resolves to central's own database and schema");
        expect($store->scope('cs07'))->toBeNull()
            ->and($store->publishedLayers())->toBe([]);
    } finally {
        tenancy()->end();
    }

    expect(cs07CentralFingerprint())->toBe($before, 'central\'s store is byte-untouched');
});

/**
 * Review 05:46Z: a host that does NOT list ConfigLadderTenancyBootstrapper (so the overlay never applies or reverts).
 * stancl's own TenancyInitialized / TenancyEnded still clear the store's cached tenant-target answer (laravel-beam's
 * provider listens to them), so after an end + re-initialize of the same tenant whose table was dropped, the next write is
 * refused rather than falling through ,public to central.
 */
it('clears a cached ok on stancl\'s own tenancy transitions with no ladder bootstrapper listed', function () {
    cs07Bootstrappers();
    config()->set('tenancy.bootstrappers', [DatabaseTenancyBootstrapper::class, CacheTenancyBootstrapper::class]);
    cs07OperatorPublish([['pointer' => '/theme', 'op' => 'set', 'value' => 'dark']]);
    $alpha = cs07Schema('alpha');
    $before = cs07CentralFingerprint();

    tenancy()->initialize($alpha);
    expect(app(ConfigOverrideStore::class)->tenantTargetProblem())->toBeNull('ok, and cached for this frame');
    tenancy()->end();

    DB::connection('central')->statement('drop table "'.$alpha->database()->getName().'".'.Beam::table('config_scopes').' cascade');

    tenancy()->initialize($alpha);
    try {
        expect(fn () => app(ConfigOverrideStore::class)->saveDraft('cs07', [['pointer' => '/review', 'op' => 'set', 'value' => true]]))
            ->toThrow(ValidationException::class, "lacks the store's tables `".Beam::table('config_scopes').'`');
    } finally {
        tenancy()->end();
    }

    expect(cs07CentralFingerprint())->toBe($before, 'central\'s store is byte-untouched');
});

it('refuses the tenant tier with no tenant active on the central connection', function () {
    cs07Bootstrappers();
    cs07OperatorPublish([['pointer' => '/theme', 'op' => 'set', 'value' => 'dark']]);
    $before = cs07CentralFingerprint();

    expect(fn () => app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->saveDraft('cs07', [['pointer' => '/review', 'op' => 'set', 'value' => true]]))
        ->toThrow(ValidationException::class, 'no tenant is active');
    expect(cs07CentralFingerprint())->toBe($before);
});

/*
 * config-sections ticket 09: the CUTOVER source (TenantDataOverrideSource). A host names legacy `tenants.data` keys; until
 * they are imported into tenant-tier rows, each tenant's legacy values apply in its hop as if published, through the same
 * restrictions, and revert with the hop.
 */
function cs09Legacy(): void
{
    config()->set('beam.tenancy.config_overrides.legacy_data', [
        'legacy_theme' => ['root' => 'cs07', 'pointer' => '/theme'],
        'legacy.review' => ['root' => 'cs07', 'pointer' => '/review'],
        'legacy_group' => ['root' => 'cs07', 'pointer' => '/group', 'entries' => true],
    ]);
}

function cs09WithData(Tenant $tenant, array $data): Tenant
{
    foreach ($data as $key => $value) {
        $tenant->setAttribute($key, $value);
    }
    $tenant->save();

    return Tenant::find($tenant->getTenantKey());
}

/** @return array{review: mixed, theme: mixed, label: mixed} */
function cs09Inside(Tenant $tenant): array
{
    tenancy()->initialize($tenant);

    try {
        return ['review' => config('cs07.review'), 'theme' => config('cs07.theme'), 'label' => config('cs07.group.label')];
    } finally {
        tenancy()->end();
    }
}

it('lays a tenant\'s legacy tenants.data values in its hop until they are published, and reverts them', function () {
    cs07Bootstrappers();
    cs09Legacy();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'legacy_group' => ['label' => 'from legacy']]);
    $bravo = cs07Schema('bravo');

    expect(cs09Inside($alpha))->toBe(['review' => true, 'theme' => 'neon', 'label' => 'from legacy'], "alpha's legacy values apply, a map's entries one by one")
        ->and(cs09Inside($bravo))->toBe(['review' => false, 'theme' => 'light', 'label' => 'base'], 'bravo has no legacy data')
        ->and([config('cs07.review'), config('cs07.theme'), config('cs07.group.label')])->toBe([false, 'light', 'base'], 'central never sees them')
        ->and(cs09Inside($alpha)['theme'])->toBe('neon', 'a second hop re-applies');
});

it('lets a published tenant row win over the legacy value at the same pointer, and keeps the other legacy values', function () {
    cs07Bootstrappers();
    cs09Legacy();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true]]);
    cs07TenantPublish($alpha, [['pointer' => '/theme', 'op' => 'set', 'value' => 'pink']]);

    expect(cs09Inside($alpha))->toBe(['review' => true, 'theme' => 'pink', 'label' => 'base'])
        ->and(config('cs07.theme'))->toBe('light', 'revert restores the published layer and the legacy layer together');
});

it('stops a legacy value at an operator lock, as it stops a published one', function () {
    cs07Bootstrappers();
    cs09Legacy();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true]]);
    expect(cs09Inside($alpha)['review'])->toBeTrue();

    app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->lock('cs07', '/review', 'Review policy is set by the operator.');

    expect(cs09Inside($alpha))->toBe(['review' => false, 'theme' => 'neon', 'label' => 'base'], 'the lock takes the key away; the unlocked legacy value stays');
});

it('takes a null or absent legacy value as no override, and a host naming no legacy keys lays nothing', function () {
    cs07Bootstrappers();
    cs09Legacy();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => null]);
    expect(cs09Inside($alpha))->toBe(['review' => false, 'theme' => 'light', 'label' => 'base']);

    config()->set('beam.tenancy.config_overrides.legacy_data', []);
    $bravo = cs09WithData(cs07Schema('bravo'), ['legacy_theme' => 'neon']);
    expect(cs09Inside($bravo)['theme'])->toBe('light', 'no map, no cutover');
});

/*
 * config-sections ticket 09: the IMPORT (splicewire:beam:tenancy:import-config-overrides). Fake values only (F14).
 */
function cs09LegacyWithSecret(): void
{
    cs09Legacy();
    // Secret overrides are stored with Laravel's `encrypted` cast; the harness boots no app key (a test-only key, as
    // laravel-beam's ConfigTenantTierTest sets one).
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('beam.tenancy.config_overrides.legacy_data.legacy_stripe', ['root' => 'cs09sec', 'pointer' => '/secret']);
    config()->set('cs09sec', ['secret' => null]);
    app(ConfigSectionRegistry::class)->section(LegacySecretSection::class, owner: 'host');
}

function cs09Import(array $options = []): string
{
    Artisan::call('splicewire:beam:tenancy:import-config-overrides', $options);

    return Artisan::output();
}

it('imports legacy values into published tenant-tier rows, idempotently, reporting per tenant and printing no value', function () {
    cs07Bootstrappers();
    cs09LegacyWithSecret();
    $fake = 'sk_test_cs09_FAKE_not_a_real_key';
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'legacy_stripe' => $fake]);
    cs07Schema('bravo');

    $out = cs09Import();
    expect($out)->toContain('alpha: imported (3)')
        ->and($out)->toContain('bravo: nothing to import')
        ->and($out)->not->toContain($fake)
        ->and($out)->not->toContain('neon');

    // The rows now carry the values: with the legacy data gone, the hop still has them.
    $alpha = cs09WithData($alpha, ['legacy_theme' => null, 'legacy' => null, 'legacy_stripe' => null]);
    tenancy()->initialize($alpha);
    try {
        expect([config('cs07.theme'), config('cs07.review'), config('cs09sec.secret')])->toBe(['neon', true, $fake]);
        $row = DB::table(Beam::table('config_overrides'))->where('section', 'cs09sec')->where('pointer', '/secret')->first(['value', 'encrypted_value']);
        expect($row->value)->toBeNull('a secret is never stored in the plain value column')
            ->and($row->encrypted_value)->not->toBeNull()
            ->and($row->encrypted_value)->not->toContain($fake, 'encrypted at rest');
    } finally {
        tenancy()->end();
    }
    expect(config('cs09sec.secret'))->toBeNull('central never sees the tenant secret');

    // Idempotent.
    $again = cs09WithData($alpha, ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'legacy_stripe' => $fake]);
    expect(cs09Import(['--tenant' => [$again->getTenantKey()]]))->toContain('alpha: already present');
});

it('writes nothing on a dry run, and reports a legacy value an operator lock stops as not importable', function () {
    cs07Bootstrappers();
    cs09LegacyWithSecret();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true]]);

    expect(cs09Import(['--dry-run' => true]))->toContain('alpha: would import (2)');
    tenancy()->initialize($alpha);
    try {
        expect(DB::table(Beam::table('config_scopes'))->where('section', 'cs07')->count())->toBe(0, 'the dry run wrote nothing');
    } finally {
        tenancy()->end();
    }

    app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->lock('cs07', '/review', 'Review policy is set by the operator.');
    expect(cs09Import())->toContain('alpha: imported (1); not importable: cs07/review');
});
