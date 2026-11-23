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
use Splicewire\Beam\Tenancy\Config\TenantConfigOverrideImporter;
use Splicewire\Beam\Tenancy\Config\TenantConfigOverridePublisher;
use Splicewire\Beam\Tenancy\ConfigLadderTenancyBootstrapper;
use Splicewire\Beam\Tenancy\Features\TenantRoleAlias;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantDataOverrideSource;
use Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder\ChatLadderSection;
use Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder\FailingImportStore;
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

/*
 * config-sections ticket 09, review REVISE (22f92af6; lead rulings): B1, S1, S2, S3, S4, S5. Fake values only (F14).
 */
it('B1: ends the cutover at the import: an override the tenant unsets after it stays unset, never the legacy value', function () {
    cs07Bootstrappers();
    cs09Legacy();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'legacy_group' => ['label' => 'from legacy']]);

    expect(cs09Import())->toContain('alpha: imported (3)');
    $alpha = Tenant::find('alpha');
    expect(TenantDataOverrideSource::imported($alpha))->toBeTrue('the import marks the tenant it finished');

    // The tenant resets two keys in the Config area: an unset of each, published.
    cs07TenantPublish($alpha, [['pointer' => '/theme', 'op' => 'unset'], ['pointer' => '/group/label', 'op' => 'unset']]);

    expect(cs09Inside($alpha))->toBe(['review' => true, 'theme' => 'light', 'label' => 'base'], 'central for the unset keys, the published value for the rest; the legacy data still says neon');
});

it('B1: an imported tenant whose store later cannot be read runs on central, never on its stale legacy values', function () {
    cs07Bootstrappers();
    cs09Legacy();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true]]);
    expect(cs09Import())->toContain('alpha: imported (2)');

    // Its store's tables are gone (a broken restore, a half-migrated schema): the tier reads nothing published.
    foreach (['config_scopes', 'config_overrides', 'versions'] as $name) {
        DB::connection('central')->statement('drop table if exists "tenant_alpha"."'.Beam::table($name).'" cascade');
    }

    expect(cs09Inside(Tenant::find('alpha')))->toBe(['review' => false, 'theme' => 'light', 'label' => 'base'], 'central; the legacy data still says neon');
});

it('S1: keeps the other legacy values after a tenant publishes one key mid-hop', function () {
    cs07Bootstrappers();
    cs09Legacy();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true]]);

    tenancy()->initialize($alpha);
    try {
        expect([config('cs07.theme'), config('cs07.review')])->toBe(['neon', true]);
        $store = app(ConfigOverrideStore::class);
        $store->saveDraft('cs07', [['pointer' => '/theme', 'op' => 'set', 'value' => 'pink']]);
        $store->publish('cs07');

        expect([config('cs07.theme'), config('cs07.review')])->toBe(['pink', true], 'the published key, and the legacy review still applies for the rest of the request');
    } finally {
        tenancy()->end();
    }
    expect([config('cs07.theme'), config('cs07.review')])->toBe(['light', false]);
});

it('S2: skips a legacy value its schema rejects, or that cannot be written at all, and the hop still starts', function () {
    cs07Bootstrappers();
    cs09Legacy();
    config()->set('beam.tenancy.config_overrides.legacy_data.legacy_through_scalar', ['root' => 'cs07', 'pointer' => '/theme', 'entries' => true]);
    $alpha = cs09WithData(cs07Schema('alpha'), [
        'legacy_theme' => ['not' => 'a string'],            // the schema says string
        'legacy' => ['review' => true],                      // valid, kept
        'legacy_through_scalar' => ['x' => 'through a scalar'], // /theme/x below central's string 'light'
    ]);

    expect(cs09Inside($alpha))->toBe(['review' => true, 'theme' => 'light', 'label' => 'base'])
        ->and(config('cs07.theme'))->toBe('light');
});

it('S3: keeps the role expanded when laravel-beam\'s operator re-sync re-lays the tenant layer mid-hop', function () {
    cs07Bootstrappers();
    app(ConfigSectionRegistry::class)->section(ChatLadderSection::class, owner: 'host');
    // The floor must declare the full app.chat shape, including default_provider: ChatLadderSection added it as a
    // nullable Tenant-tier key, and schemastud keeps a nullable-with-default property REQUIRED on the stored
    // (non-request) axis the AcceptanceGate validates. A floor without the key makes TenantDataOverrideSource::valid()
    // reject every app.chat legacy entry, dropping the whole layer to floor-model (the S3 regression root 11-23 found).
    config()->set('app.chat', ['default_model' => 'floor-model', 'default_provider' => null, 'models' => ['floor-model' => ['label' => 'Floor']]]);
    config()->set('beam.tenancy.config_overrides.legacy_data', [
        'llm_config.preferred_model' => ['root' => 'app.chat', 'pointer' => '/default_model'],
        'llm_config.models' => ['root' => 'app.chat', 'pointer' => '/models', 'entries' => true],
    ]);
    // 09's feature list: no stancl TenantConfig, no TenantModelCatalog.
    app(TenantRoleAlias::class)->bootstrap(tenancy());
    $acme = cs09WithData(cs07Schema('acme'), ['llm_config' => [
        'preferred_model' => 'fast',
        'provider' => 'acme-llm',
        'models' => ['tenant-only-model' => ['label' => 'Tenant only']],
        'roles' => ['acme-llm' => ['fast' => 'tenant-only-model']],
    ]]);

    tenancy()->initialize($acme);
    try {
        expect(config('app.chat.default_model'))->toBe('tenant-only-model', 'the laddered role expands at the hop');

        // An operator publish elsewhere moves the token; the next sync (the JobProcessing listener's) lifts the tenant
        // layer, writes the operator tier and lays the layer again.
        cs07OperatorPublish([['pointer' => '/theme', 'op' => 'set', 'value' => 'operator-teal']]);
        app(ConfigOverlay::class)->recheckNow();
        app(ConfigOverlay::class)->sync();

        expect(config('cs07.theme'))->toBe('operator-teal', 'CONTROL: the re-sync really ran mid-hop')
            ->and(config('app.chat.default_model'))->toBe('tenant-only-model', 'never the raw role after the re-lay');
    } finally {
        tenancy()->end();
    }
    expect(config('app.chat.default_model'))->toBe('floor-model', 'central after the hop');
});

it('S4: never publishes a tenant\'s pending draft: that root is skipped and reported, and the tenant is not marked', function () {
    cs07Bootstrappers();
    cs09Legacy();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon']);

    // The tenant admin saved a draft and has not published it.
    tenancy()->initialize($alpha);
    try {
        app(ConfigOverrideStore::class)->saveDraft('cs07', [['pointer' => '/review', 'op' => 'set', 'value' => true]]);
    } finally {
        tenancy()->end();
    }

    expect(cs09Import())->toContain('alpha: nothing to import; cs07 (draft pending, skipped); not marked imported')
        ->and(TenantDataOverrideSource::imported(Tenant::find('alpha')))->toBeFalse();

    tenancy()->initialize($alpha);
    try {
        $store = app(ConfigOverrideStore::class);
        $scope = $store->scope('cs07');
        expect($scope->published_version)->toBeNull('nothing published: the draft stays theirs')
            ->and($scope->head_version)->not->toBeNull();
    } finally {
        tenancy()->end();
    }
    expect(cs09Inside(Tenant::find('alpha'))['theme'])->toBe('neon', 'unmarked: the legacy value still applies');
});

it('S5: a failed import prints the exception class, never its message or a value', function () {
    cs07Bootstrappers();
    cs09Legacy();
    cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon-cs09-leak-probe']);

    $failing = app()->make(FailingImportStore::class);
    app()->instance(ConfigOverrideStore::class, $failing);

    $exit = Artisan::call('splicewire:beam:tenancy:import-config-overrides');
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('alpha: failed (Illuminate\\Database\\QueryException')
        ->and($out)->not->toContain('neon-cs09-leak-probe')
        ->and($out)->not->toContain('insert into');
});

/*
 * config-sections ticket 16: the tower writers (operator pinned model/provider, System › Models catalog, the promotion
 * sink) publish a tenant's `app.chat` choices as TENANT-tier Override-Ladder versions through
 * {@see TenantConfigOverridePublisher}, instead of writing stancl `llm_config`. The three writers live in the tower
 * package; what is PROVEN here is the publisher they all call — laid in the hop, reverting with it, the legacy
 * setLlmConfig null-semantics, the extend catalog, the lazy cutover, and a tier refusal surfacing (never a 200 no-op).
 * `app.chat` is the {@see ChatLadderSection} fixture (ticket 16 added its `default_provider`). Fake model/provider ids
 * only (F14).
 */
function cs16Chat(): void
{
    app(ConfigSectionRegistry::class)->section(ChatLadderSection::class, owner: 'host');
    config()->set('app.chat', [
        'default_model' => 'floor-model',
        'default_provider' => null,
        'models' => ['floor-model' => ['label' => 'Floor']],
    ]);
    // The host's cutover map: the publisher finishes it lazily on a tenant's first write.
    config()->set('beam.tenancy.config_overrides.legacy_data', [
        'llm_config.preferred_model' => ['root' => 'app.chat', 'pointer' => '/default_model'],
        'llm_config.provider' => ['root' => 'app.chat', 'pointer' => '/default_provider'],
        'llm_config.models' => ['root' => 'app.chat', 'pointer' => '/models', 'entries' => true],
    ]);
}

/** @return array{model: mixed, provider: mixed, models: mixed} what `config('app.chat.*')` holds inside `$tenant`'s hop */
function cs16ChatInside(Tenant $tenant): array
{
    tenancy()->initialize($tenant);

    try {
        return [
            'model' => config('app.chat.default_model'),
            'provider' => config('app.chat.default_provider'),
            'models' => config('app.chat.models'),
        ];
    } finally {
        tenancy()->end();
    }
}

/** Run `$fn` inside `$tenant`'s hop: the in-hop writers (System › Models, the promotion sink) run with the hop held. */
function cs16InHop(Tenant $tenant, Closure $fn): void
{
    tenancy()->initialize($tenant);

    try {
        $fn();
    } finally {
        tenancy()->end();
    }
}

it('publishes a tenant operator-pin of model and provider as tenant-tier app.chat overrides, laid in that tenant\'s hop only', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs07Schema('alpha');
    $bravo = cs07Schema('bravo');

    // The operator endpoint holds no hop: setChatDefaultsForTenant opens the target tenant's hop around the publish.
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, 'alpha-model', 'alpha-provider');

    expect(cs16ChatInside($alpha))->toMatchArray(['model' => 'alpha-model', 'provider' => 'alpha-provider'])
        ->and(cs16ChatInside($bravo))->toMatchArray(['model' => 'floor-model', 'provider' => null], 'bravo inherits the floor, never alpha\'s pin')
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central never sees alpha')
        // The lazy cutover is per ROOT (config-sections ticket 16, D2): a single app.chat write retires only app.chat's
        // legacy, never the whole tenant — the whole-tenant marker stays unset so every OTHER root keeps laying from legacy.
        ->and(TenantDataOverrideSource::importedRoot(Tenant::find('alpha'), 'app.chat'))->toBeTrue('the first write finishes app.chat\'s lazy cutover')
        ->and(TenantDataOverrideSource::imported(Tenant::find('alpha')))->toBeFalse('one root\'s write never marks the whole tenant imported')
        ->and(cs16ChatInside($alpha)['model'])->toBe('alpha-model', 'a second hop re-applies the published pin');

    // The pin is alpha's own tenant-tier version, nowhere central.
    expect(DB::connection('central')->table(Beam::table('config_scopes'))->where('section', 'app.chat')->count())->toBe(0, 'central holds no app.chat scope');
});

it('publishes a tenant pin through the publisher onto the tenant\'s OWN DATABASE on the one server, reads it back there, and leaves nothing on the central connection (LANE-B-0500 3a)', function () {
    cs07Bootstrappers();
    cs16Chat();

    // The own-DATABASE shape this harness stands up on a SINGLE server: a tenant whose rows live in a DIFFERENT
    // Postgres database (pool_remote -> PG_TEST_DATABASE_pool2) than central, reached WITHOUT a second server
    // (no CS07_PG2) -- the one-server own-database proof the relocation test's else-branch also lands on. It proves
    // the amendment's condition directly for the publisher: the write runs on the tenant's RESOLVED connection, so
    // the app.chat version is stored on the tenant's own database and the central connection holds no row.
    // defineDatabaseMigrations drops pool_* (not tenant_*) schemas on the remote connection; drop a prior run's here.
    foreach (DB::connection(PostgresTestCase::REMOTE_CONNECTION)->select("select schema_name from information_schema.schemata where schema_name like 'tenant\\_%'") as $row) {
        DB::connection(PostgresTestCase::REMOTE_CONNECTION)->statement('drop schema "'.$row->schema_name.'" cascade');
    }
    $alpha = cs07Schema('alpha', PostgresTestCase::REMOTE_CONNECTION);

    // central and the tenant's own database are two different databases on the one server.
    expect(DB::connection('central')->selectOne('select current_database() as d')->d)->toBe(getenv('PG_TEST_DATABASE'))
        ->and(PostgresTestCase::remoteDatabase())->not->toBe(getenv('PG_TEST_DATABASE'));

    // The operator endpoint holds no hop: setChatDefaultsForTenant opens alpha's hop around the publish, so the write
    // runs on alpha's resolved connection (its own database), not central.
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, 'own-db-model', 'own-db-provider');

    // The pin is effective inside the hop, and central reverts to the floor after it.
    expect(cs16ChatInside($alpha))->toMatchArray(['model' => 'own-db-model', 'provider' => 'own-db-provider'])
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central reverts to the floor after the hop');

    // The version is PHYSICALLY on the tenant's own database (current_database() is the pool-remote database), and the
    // central connection holds no app.chat scope. A write that leaked to the central connection flips BOTH assertions:
    // the tenant-database scope count drops to 0 and the central scope count rises to 1 (LANE-B-0500 3a mutation).
    tenancy()->initialize($alpha);
    expect(DB::connection('tenant')->selectOne('select current_database() as d')->d)->toBe(PostgresTestCase::remoteDatabase(), 'the publish and the read-back ran on the tenant\'s own database')
        ->and(DB::table(Beam::table('config_scopes'))->where('section', 'app.chat')->count())->toBe(1, 'the app.chat version lives in the tenant\'s own database');
    tenancy()->end();

    expect(DB::connection('central')->table(Beam::table('config_scopes'))->where('section', 'app.chat')->count())->toBe(0, 'the central connection holds no app.chat scope -- the publisher never wrote there');
});

it('clears the pin only when both model and provider are null, and leaves an unmentioned field standing otherwise (legacy setLlmConfig semantics)', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs07Schema('alpha');
    $pub = app(TenantConfigOverridePublisher::class);

    $pub->setChatDefaultsForTenant($alpha, 'm1', 'p1');
    expect(cs16ChatInside($alpha))->toMatchArray(['model' => 'm1', 'provider' => 'p1']);

    // Model alone: the provider is left standing (single-null leaves the other, the array_filter-drop-nulls merge).
    $pub->setChatDefaultsForTenant($alpha, 'm2', null);
    expect(cs16ChatInside($alpha))->toMatchArray(['model' => 'm2', 'provider' => 'p1'], 'the untouched provider stays');

    // Provider alone: the model is left standing.
    $pub->setChatDefaultsForTenant($alpha, null, 'p2');
    expect(cs16ChatInside($alpha))->toMatchArray(['model' => 'm2', 'provider' => 'p2'], 'the untouched model stays');

    // Both null clears the pin entirely, inheriting the floor — the legacy "both empty clears the carrier".
    $pub->setChatDefaultsForTenant($alpha, null, null);
    expect(cs16ChatInside($alpha))->toMatchArray(['model' => 'floor-model', 'provider' => null], 'both unset: back to the platform floor');
});

it('replaces the tenant extend catalog through setChatModels: adds and drops ids atop the floor, and refuses a floor-id collision with a visible error', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs07Schema('alpha');

    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, ['m-a' => ['label' => 'A'], 'm-b' => ['label' => 'B']]));
    expect(array_keys(cs16ChatInside($alpha)['models']))->toContain('floor-model')->toContain('m-a')->toContain('m-b');

    // The FULL desired map: m-b is dropped, m-c is added, m-a stays; the floor is never touched.
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, ['m-a' => ['label' => 'A'], 'm-c' => ['label' => 'C']]));
    $keys = array_keys(cs16ChatInside($alpha)['models']);
    expect($keys)->toContain('m-a')->toContain('m-c')->toContain('floor-model')
        ->and($keys)->not->toContain('m-b'); // a dropped id is unset, not left behind

    // An extend id that collides with a platform-floor model is refused by the store — visible, never a silent no-op.
    cs16InHop($alpha, function () use ($alpha) {
        expect(fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, ['floor-model' => ['label' => 'Hijack']]))
            ->toThrow(ValidationException::class);
    });
    expect(cs16ChatInside($alpha)['models']['floor-model'])->toBe(['label' => 'Floor'], 'the floor row is untouched by the refused write');
});

it('adds one model through addChatModel without reading or dropping the tenant\'s other published models', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs07Schema('alpha');

    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, ['existing' => ['label' => 'Existing']]));
    // The promotion sink offers a newly-discovered model: add-only, it never reads the current map.
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->addChatModel($alpha, 'offered', ['label' => 'Offered']));

    expect(array_keys(cs16ChatInside($alpha)['models']))->toContain('existing')->toContain('offered')->toContain('floor-model');
});

it('finishes the cutover on the first write, importing the legacy llm_config, so a model the tenant later drops never returns from the legacy data', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs09WithData(cs07Schema('alpha'), ['llm_config' => [
        'preferred_model' => 'legacy-model',
        'provider' => 'legacy-provider',
        'models' => ['legacy-only' => ['label' => 'Legacy only']],
    ]]);
    expect(TenantDataOverrideSource::imported($alpha))->toBeFalse()
        // Before any write, the legacy values lay in the hop through the cutover source.
        ->and(cs16ChatInside($alpha))->toMatchArray(['model' => 'legacy-model', 'provider' => 'legacy-provider']);

    // The first publisher write imports the legacy data as published overrides, then applies its own op and marks the ROOT.
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, 'pinned-model', null);
    $alpha = Tenant::find('alpha');
    expect(TenantDataOverrideSource::importedRoot($alpha, 'app.chat'))->toBeTrue('the first write finished app.chat\'s cutover')
        ->and(TenantDataOverrideSource::imported($alpha))->toBeFalse('a single-root write leaves the whole-tenant marker unset')
        ->and(array_keys(cs16ChatInside($alpha)['models']))->toContain('legacy-only') // the legacy catalog was imported
        ->and(cs16ChatInside($alpha)['model'])->toBe('pinned-model', 'the pin overwrites the imported legacy default');

    // The tenant drops its whole catalog: legacy-only is unset and, because the tenant is now imported, never re-laid.
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, []));
    expect(array_keys(cs16ChatInside($alpha)['models']))->not->toContain('legacy-only') // the dropped model never comes back from tenants.data
        ->and(cs16ChatInside($alpha)['model'])->toBe('pinned-model', 'the pin still stands');
});

it('refuses to write when the tenant has an unpublished app.chat draft, rather than publish that draft for them', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs07Schema('alpha');

    // Mark imported so ensureImportedInHop is a no-op: this isolates publishInHop's own pending-draft guard.
    app(TenantConfigOverrideImporter::class)->mark($alpha);
    $alpha = Tenant::find('alpha');

    // The tenant admin saved a draft they have not published.
    cs16InHop($alpha, fn () => app(ConfigOverrideStore::class)->saveDraft('app.chat', [['pointer' => '/default_model', 'op' => 'set', 'value' => 'their-draft']]));

    cs16InHop($alpha, function () use ($alpha) {
        expect(fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, ['m-a' => ['label' => 'A']]))
            ->toThrow(ValidationException::class);
    });

    // Their draft stays theirs (never published), and the publisher's write did not land.
    cs16InHop($alpha, function () {
        $scope = app(ConfigOverrideStore::class)->scope('app.chat');
        expect($scope->published_version)->toBeNull('the tenant draft was never published for them')
            ->and($scope->head_version)->not->toBeNull('their draft is still there');
    });
    expect(array_keys(cs16ChatInside($alpha)['models']))->not->toContain('m-a'); // the refused write left nothing
});

/*
 * config-sections ticket 16 — LANE-B-0820 amend (coord-lead). Folded fixes, each red-first:
 *   F1: a provider-qualified id (openai/gpt-4o) is ONE escaped pointer segment through setChatModels AND addChatModel.
 *   F2: setChatModels(null) is the documented "clear every model" input, not a TypeError.
 *   D1: pinnedChatDefaults reads the tenant's OWN pin PRESENCE (null when unpinned), so the "pinned" response round-trips.
 *   D2: the lazy cutover is per ROOT — an app.chat write retires ONLY app.chat's legacy, never wedged by an unrelated
 *       root's draft and never stranding another root's legacy; the bulk import still imports every root and marks the
 *       tenant entirely. Fake model/provider ids only (F14).
 */
function cs16D2Fixture(): void
{
    app(ConfigSectionRegistry::class)->section(ChatLadderSection::class, owner: 'host');
    config()->set('app.chat', ['default_model' => 'floor-model', 'default_provider' => null, 'models' => ['floor-model' => ['label' => 'Floor']]]);
    config()->set('beam.tenancy.config_overrides.legacy_data', [
        // app.chat — the root THIS unit's writers cut over.
        'llm_config.preferred_model' => ['root' => 'app.chat', 'pointer' => '/default_model'],
        'llm_config.models' => ['root' => 'app.chat', 'pointer' => '/models', 'entries' => true],
        // cs07 — an UNRELATED root this unit never writes; its legacy must keep laying until the bulk import.
        'legacy_theme' => ['root' => 'cs07', 'pointer' => '/theme'],
        'legacy.review' => ['root' => 'cs07', 'pointer' => '/review'],
    ]);
}

it('F1: a provider-qualified model id is ONE escaped pointer segment through setChatModels and addChatModel, never three', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs07Schema('alpha');

    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, ['openai/gpt-4o' => ['label' => 'GPT-4o']]));
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->addChatModel($alpha, 'anthropic/claude-opus', ['label' => 'Opus']));

    $models = cs16ChatInside($alpha)['models'];
    expect($models)->toHaveKey('openai/gpt-4o')->toHaveKey('anthropic/claude-opus')
        ->and($models['openai/gpt-4o'])->toBe(['label' => 'GPT-4o'], 'the id addresses ONE entry, not a nested openai -> gpt-4o tree')
        ->and($models)->not->toHaveKey('openai', 'no malformed intermediate pointer from splitting the id on /');

    // The published pointer is the RFC 6901 escape of ['models', 'openai/gpt-4o'] — a single segment (/ -> ~1), not /models/openai/gpt-4o.
    cs16InHop($alpha, function () {
        $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
        $pointers = array_keys($store->publishedLayer($store->scope('app.chat')));
        // the / in the id is escaped ~1 (one pointer segment), and the id was never split into /models/openai/gpt-4o
        expect($pointers)->toContain('/models/openai~1gpt-4o')
            ->and($pointers)->not->toContain('/models/openai');
    });
});

it('F2: setChatModels(null) clears every published tenant model without throwing (the LlmModelsInputData clear case)', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs07Schema('alpha');

    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, ['m-a' => ['label' => 'A'], 'm-b' => ['label' => 'B']]));
    expect(array_keys(cs16ChatInside($alpha)['models']))->toContain('m-a')->toContain('m-b');

    // null is the documented "clear it" input: every published model is unset, the floor remains, and nothing throws.
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, null));
    $keys = array_keys(cs16ChatInside($alpha)['models']);
    expect($keys)->not->toContain('m-a')->not->toContain('m-b')
        ->and($keys)->toContain('floor-model'); // the platform floor is untouched by the clear
});

it('D1: pinnedChatDefaults reads the tenant\'s OWN pin presence — null when unpinned (never the inherited floor), and a null round-trip stays unpinned', function () {
    cs07Bootstrappers();
    cs16Chat();
    $alpha = cs07Schema('alpha');

    // Unpinned: the hop lays the floor as the EFFECTIVE value, but the tenant has pinned nothing of its own.
    cs16InHop($alpha, function () {
        expect(config('app.chat.default_model'))->toBe('floor-model', 'the floor is the effective value in the hop')
            ->and(app(TenantConfigOverridePublisher::class)->pinnedChatDefaults())
            ->toBe(['model' => null, 'provider' => null], 'but the tenant\'s OWN pin is null, not the floor id');
    });

    // Round-trip the unpinned response: re-sending null for both writes NO pin (never the floor id as a hard pin).
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, null, null);
    cs16InHop($alpha, function () {
        $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
        $scope = $store->scope('app.chat');
        $published = $scope === null ? [] : array_keys($store->publishedLayer($scope));
        expect($published)->not->toContain('/default_model') // the null round-trip wrote no default_model pin
            ->and(app(TenantConfigOverridePublisher::class)->pinnedChatDefaults()['model'])->toBeNull();
    });

    // A real pin is reported back verbatim; clearing it returns to null (round-trips both ways).
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, 'alpha-pin', 'alpha-prov');
    cs16InHop($alpha, fn () => expect(app(TenantConfigOverridePublisher::class)->pinnedChatDefaults())->toBe(['model' => 'alpha-pin', 'provider' => 'alpha-prov']));
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, null, null);
    cs16InHop($alpha, fn () => expect(app(TenantConfigOverridePublisher::class)->pinnedChatDefaults())->toBe(['model' => null, 'provider' => null], 'clearing the pin returns to unpinned'));
});

it('D2: an unrelated root\'s pending draft never blocks an app.chat write (the lazy cutover is scoped to the root being written)', function () {
    cs07Bootstrappers();
    cs16D2Fixture();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'llm_config' => ['models' => ['legacy-only' => ['label' => 'Legacy only']]]]);

    // The tenant admin has an UNPUBLISHED draft on the unrelated cs07 root.
    cs16InHop($alpha, fn () => app(ConfigOverrideStore::class)->saveDraft('cs07', [['pointer' => '/review', 'op' => 'set', 'value' => false]]));

    // The app.chat write succeeds: ensureImportedInHop imports ONLY app.chat, so cs07's pending draft cannot wedge it.
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, ['m-a' => ['label' => 'A']]));
    expect(array_keys(cs16ChatInside($alpha)['models']))->toContain('m-a'); // the app.chat write landed despite cs07's draft

    $alpha = Tenant::find('alpha');
    expect(TenantDataOverrideSource::importedRoot($alpha, 'app.chat'))->toBeTrue('app.chat cut over')
        ->and(TenantDataOverrideSource::importedRoot($alpha, 'cs07'))->toBeFalse('cs07 was NOT imported by the app.chat write')
        ->and(TenantDataOverrideSource::imported($alpha))->toBeFalse('and the whole tenant is not marked');

    // cs07's draft stays theirs (never published for them) and its legacy still lays for the unpublished key.
    cs16InHop($alpha, function () {
        $scope = app(ConfigOverrideStore::class)->scope('cs07');
        expect($scope->published_version)->toBeNull('the cs07 draft was never published by the app.chat write')
            ->and($scope->head_version)->not->toBeNull('their cs07 draft is still there');
    });
});

it('D2: after app.chat cuts over per-root, every OTHER root keeps laying from legacy, and the bulk import later imports it and marks the tenant', function () {
    cs07Bootstrappers();
    cs16D2Fixture();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'llm_config' => ['preferred_model' => 'legacy-model', 'models' => ['legacy-only' => ['label' => 'Legacy only']]]]);

    // app.chat cuts over on its own writer, retiring ONLY app.chat's legacy.
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, 'pinned-model', null);
    $alpha = Tenant::find('alpha');

    // app.chat's own dropped model would never resurrect (per-root marker), AND cs07's legacy is untouched — still laid.
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, []));
    expect(array_keys(cs16ChatInside($alpha)['models']))->not->toContain('legacy-only') // app.chat's dropped model stays dropped (per-root cutover)
        ->and(cs09Inside($alpha))->toMatchArray(['theme' => 'neon', 'review' => true], 'cs07 legacy still applies — the app.chat cutover never stranded it');

    // The bulk import now runs: it imports cs07 (finds app.chat already published) and marks the WHOLE tenant.
    expect(cs09Import())->toContain('alpha: ');
    $alpha = Tenant::find('alpha');
    expect(TenantDataOverrideSource::imported($alpha))->toBeTrue('the bulk import marks the whole tenant');

    // With the legacy data gone, cs07's values survive as published rows — they were imported, not stranded.
    $alpha = cs09WithData($alpha, ['legacy_theme' => null, 'legacy' => null]);
    expect(cs09Inside($alpha))->toMatchArray(['theme' => 'neon', 'review' => true], 'cs07 imported to published rows');
});

it('D2: the bulk import still imports ALL roots at once and marks the tenant entirely (the per-root path never changed it)', function () {
    cs07Bootstrappers();
    cs16D2Fixture();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'llm_config' => ['preferred_model' => 'legacy-model', 'models' => ['legacy-only' => ['label' => 'Legacy only']]]]);

    expect(cs09Import())->toContain('alpha: imported');
    $alpha = Tenant::find('alpha');
    expect(TenantDataOverrideSource::imported($alpha))->toBeTrue('the bulk import marks the whole tenant, as before');

    // Every root's legacy is now a published row: with the legacy data gone, both roots still read their values.
    $alpha = cs09WithData($alpha, ['legacy_theme' => null, 'legacy' => null, 'llm_config' => null]);
    expect(cs09Inside($alpha))->toMatchArray(['theme' => 'neon', 'review' => true], 'cs07 imported')
        ->and(cs16ChatInside($alpha))->toMatchArray(['model' => 'legacy-model'], 'app.chat imported');
});

/*
 * config-sections ticket 16 — LANE-B-0925 MEDIUM (coord-lead). The whole-tenant bulk import must skip a root a lazy
 * writer already cut over: its legacy is retired, and re-importing it would re-publish a value the tenant dropped after
 * the cutover. Fixed in TenantConfigOverrideImporter::importInsideHop (the importedRoots skip at the top of the loop).
 * Red-first against the pre-fix importInsideHop (no importedRoots skip): it reached $registered = sections->get($root)
 * for the cut-over root and, finding /models/legacy-only uncovered, re-published it — resurrecting the dropped model
 * and over-counting $imported.
 */
it('D2 MEDIUM: the bulk import skips an already-cut-over root, so a model the tenant dropped after the cutover is never resurrected as a published row', function () {
    cs07Bootstrappers();
    cs16D2Fixture();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'llm_config' => ['preferred_model' => 'legacy-model', 'models' => ['legacy-only' => ['label' => 'Legacy only']]]]);

    // app.chat cuts over on its own writer (importing legacy-only among its models), then the tenant drops every model.
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, 'pinned-model', null);
    $alpha = Tenant::find('alpha');
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, []));
    expect(array_keys(cs16ChatInside($alpha)['models']))->not->toContain('legacy-only'); // dropped at apply time (per-root legacy retired)

    // The whole-tenant bulk import runs: app.chat is already in importedRoots, so importInsideHop must skip it rather
    // than re-publish its retired legacy, and still mark the whole tenant.
    expect(cs09Import())->toContain('alpha: ');
    $alpha = Tenant::find('alpha');
    expect(TenantDataOverrideSource::imported($alpha))->toBeTrue('the import still marks the whole tenant');

    // With the legacy data cleared, the dropped model stays gone — the import did not resurrect it as a published row,
    // and cs07's unrelated legacy was still imported, not stranded.
    $alpha = cs09WithData($alpha, ['legacy_theme' => null, 'legacy' => null, 'llm_config' => null]);
    expect(array_keys(cs16ChatInside($alpha)['models']))->not->toContain('legacy-only'); // the assertion that matters: no resurrection through the import
    expect(cs09Inside($alpha))->toMatchArray(['theme' => 'neon', 'review' => true], 'cs07 was imported, not stranded by the skip');
});

it('D2 MEDIUM: importInsideHop counts an already-cut-over root as already-imported — never refused, never skipped — and writes nothing fresh for it', function () {
    cs07Bootstrappers();
    cs16D2Fixture();
    $alpha = cs09WithData(cs07Schema('alpha'), ['legacy_theme' => 'neon', 'legacy' => ['review' => true], 'llm_config' => ['preferred_model' => 'legacy-model', 'models' => ['legacy-only' => ['label' => 'Legacy only']]]]);

    // app.chat cuts over per-root; the tenant then drops every app.chat model.
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($alpha, 'pinned-model', null);
    $alpha = Tenant::find('alpha');
    cs16InHop($alpha, fn () => app(TenantConfigOverridePublisher::class)->setChatModels($alpha, []));

    // Read the outcome directly: cs07 (two entries) is imported fresh; app.chat is skipped as already-imported, so it
    // contributes NOTHING to $imported — the pre-fix importer re-published app.chat's dropped legacy-only (imported == 3).
    $map = (array) config('beam.tenancy.config_overrides.legacy_data');
    $outcome = null;
    cs16InHop($alpha, function () use ($alpha, $map, &$outcome) {
        $outcome = app(TenantConfigOverrideImporter::class)->importInsideHop($alpha, $map, dry: false);
    });

    expect($outcome->imported)->toBe(2, 'only cs07\'s two entries are written fresh; app.chat is skipped, not resurrected')
        ->and($outcome->refused)->toBe([], 'the already-cut-over app.chat root is never refused')
        ->and($outcome->present)->toBeGreaterThan(0, 'app.chat is counted already-imported')
        ->and($outcome->completed())->toBeTrue('nothing was skipped, so the tenant may still be marked');
});

/*
 * config-sections ticket 16 COMPLETION (LANE-B-1920): the operator's provider writer moved onto the app.chat ladder
 * (TenantConfigOverridePublisher::setChatDefaults publishes `default_provider`) and no longer writes `llm_config.provider`.
 * TenantRoleAlias::expandRole reads the ROLE from the effective `app.chat.default_model` but used to read the PROVIDER from
 * tenant DATA (`llm_config.providers.text`, else `llm_config.provider`). So for a role-pinned tenant whose operator moved
 * to a new provider, the published provider was ignored and the role expanded through the stale legacy provider; with no
 * legacy provider at all, expandRole threw. The fix reads the EFFECTIVE `app.chat.default_provider` after `providers.text`
 * and before the legacy provider — the same ladder the role is read from. `app.chat` is the {@see ChatLadderSection}
 * fixture; roles stay in `llm_config` (deploy-only), so the role map is tenant data here. Fake model/provider ids (F14).
 */
it('expands a pinned role through the operator-published provider on the ladder, not the tenant\'s stale legacy provider', function () {
    cs07Bootstrappers();
    cs16Chat();
    app(TenantRoleAlias::class)->bootstrap(tenancy());

    // The tenant keeps a role map for TWO providers and a stale legacy single provider (provider-a). Since ticket 16 the
    // operator no longer writes llm_config.provider, so this value can lag behind the operator's current choice.
    $acme = cs09WithData(cs07Schema('acme'), ['llm_config' => [
        'preferred_model' => 'fast',
        'provider' => 'provider-a',
        'models' => ['model-a' => ['label' => 'A model'], 'model-b' => ['label' => 'B model']],
        'roles' => [
            'provider-a' => ['fast' => 'model-a'],
            'provider-b' => ['fast' => 'model-b'],
        ],
    ]]);

    // The operator pins the role and publishes the CURRENT provider (B) onto the app.chat ladder. The publisher finishes
    // app.chat's lazy cutover, so these published rows outrank the legacy tenants.data (legacy provider-a included).
    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($acme, 'fast', 'provider-b');

    // Inside the hop the role expands through the EFFECTIVE provider (B), to B's model — never provider-a's model-a.
    expect(cs16ChatInside($acme))->toMatchArray([
        'provider' => 'provider-b',   // CONTROL: the operator's published provider is the effective ladder value
        'model' => 'model-b',         // the role expands through provider-b, not the stale legacy provider-a (-> model-a)
    ])
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central reverts to the floor after the hop');
});

it('expands a pinned role for a tenant with NO legacy provider, through the published provider, instead of throwing', function () {
    cs07Bootstrappers();
    cs16Chat();
    app(TenantRoleAlias::class)->bootstrap(tenancy());

    // No `provider` key in llm_config at all: before the fix, the provider fallback resolved to null and expandRole threw
    // "Role [fast] has no model for the tenant's current provider [none]." on every hop of this tenant.
    $bravo = cs09WithData(cs07Schema('bravo'), ['llm_config' => [
        'preferred_model' => 'fast',
        'models' => ['model-b' => ['label' => 'B model']],
        'roles' => ['provider-b' => ['fast' => 'model-b']],
    ]]);

    app(TenantConfigOverridePublisher::class)->setChatDefaultsForTenant($bravo, 'fast', 'provider-b');

    // The hop initializes without throwing and the role expands through the published provider.
    expect(cs16ChatInside($bravo))->toMatchArray([
        'provider' => 'provider-b',
        'model' => 'model-b',
    ])
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central reverts to the floor after the hop');
});
