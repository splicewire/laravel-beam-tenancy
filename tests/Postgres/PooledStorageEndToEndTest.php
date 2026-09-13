<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Tenancy\Commands\PoolsMigrate;
use Splicewire\Beam\Tenancy\Pools\PoolMigrator;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Postgres\PostgresTestCase;
use Stancl\Tenancy\Commands\Migrate;

/**
 * pooled-storage ticket 05 — two pooled tenants in one pool on a real Postgres: the manager, the pool
 * migrator, the connector, the policy and the delete branch, end to end. The sqlite suite proves the
 * wiring with fakes; this proves the mechanism.
 */
it('keeps two pooled tenants apart in one schema, migrates the pool once, and deletes only the leaving tenant\'s rows', function () {
    $a = provisionPooled('alpha');
    $b = provisionPooled('bravo');

    expect($a->database()->getName())->toBe('pool_default')
        ->and($b->database()->getName())->toBe('pool_default');

    // The pool exists, migrated and prepared, once.
    expect(DB::selectOne("select 1 as ok from information_schema.schemata where schema_name = 'pool_default'")?->ok)->toBe(1);
    $report = app(PoolMigrator::class)->migrate('default');
    expect($report->isNoop())->toBeTrue('second migrate applies nothing');

    tenancy()->initialize($a);
    expect(DB::connection('tenant')->selectOne("select current_setting('app.tenant_id', true) v")->v)->toBe('alpha')
        ->and(DB::connection('tenant')->selectOne('select current_user u')->u)->toBe(PostgresTestCase::RLS_ROLE);
    DB::table('pooled_notes')->insert(['slug' => 'hello', 'title' => 'A hello']);
    DB::table('pooled_notes')->insert(['slug' => 'second', 'title' => 'A second']);
    expect(DB::table('pooled_notes')->count())->toBe(2);
    tenancy()->end();

    tenancy()->initialize($b);
    expect(DB::table('pooled_notes')->count())->toBe(0, 'bravo sees none of alpha\'s rows');
    DB::table('pooled_notes')->insert(['slug' => 'hello', 'title' => 'B hello']); // same slug, other tenant
    expect(DB::table('pooled_notes')->count())->toBe(1)
        ->and(DB::table('pooled_notes')->where('title', 'A hello')->exists())->toBeFalse();
    tenancy()->end();

    // Central (the owner, no setting) sees every row with FORCE off — which is why request paths
    // never reach a pool as the owner.
    expect(DB::table('pool_default.pooled_notes')->count())->toBe(3)
        ->and(collect(DB::select('select tenant_id from pool_default.pooled_notes'))->pluck('tenant_id')->sort()->values()->all())->toBe(['alpha', 'alpha', 'bravo']);

    // Leaving: alpha's rows go, bravo's stay, the pool stays.
    $a->database()->manager()->deleteDatabase($a);
    expect(collect(DB::select('select tenant_id from pool_default.pooled_notes'))->pluck('tenant_id')->all())->toBe(['bravo']);
});

it('runs the pools:migrate command twice idempotently and skips pooled tenants in tenants:migrate', function () {
    provisionPooled('alpha');
    Artisan::registerCommand(app(PoolsMigrate::class));
    Artisan::registerCommand(app(Migrate::class));

    $this->artisan('splicewire:beam:tenancy:pools:migrate', ['pool' => 'default'])
        ->expectsOutputToContain('Pool: default (pool_default) — prepared, nothing to apply')
        ->assertSuccessful();

    $this->artisan('tenants:migrate', ['--tenants' => ['alpha']])
        ->expectsOutputToContain('alpha — pooled (pool_default); skipped')
        ->assertSuccessful();
});

it('passes the pooled-storage doctor audits over a prepared pool, and fails coverage once a table loses its policy', function () {
    provisionPooled('alpha');
    provisionPooled('bravo');

    $findings = app(Splicewire\Beam\Tenancy\Doctor\PooledStorageAudit::class)->run();
    $byCheck = collect($findings)->groupBy('check')->map(fn ($f) => $f->pluck('status')->unique()->values()->all());

    expect($byCheck->keys()->all())->not->toBeEmpty()
        ->and(collect($findings)->filter(fn ($f) => $f->status === Rushing\Doctor\DoctorStatus::Fail)->map(fn ($f) => $f->check.': '.$f->detail)->all())->toBe([])
        ->and(array_key_exists('beam_pool_probe_default', config('database.connections')))->toBeFalse('probe purged');

    // The advisory frame half reports the live setting on the probe, and no Fail.
    $frame = app(Splicewire\Beam\Tenancy\Doctor\PooledStorageFrameAudit::class)->run();
    expect(collect($frame)->pluck('status')->unique()->all())->toBe([Rushing\Doctor\DoctorStatus::Pass]);

    // The markers audit passes too: both tenants carry their own key and the pool schema.
    expect(app(Splicewire\Beam\Tenancy\Doctor\PooledTenantMarkersAudit::class)->run()[0]->status)->toBe(Rushing\Doctor\DoctorStatus::Pass);

    // Break one invariant the way an operator could: RLS switched off on one pool table.
    DB::statement('alter table pool_default.pooled_notes disable row level security');
    $failed = collect(app(Splicewire\Beam\Tenancy\Doctor\PooledStorageAudit::class)->run())
        ->filter(fn ($f) => $f->status === Rushing\Doctor\DoctorStatus::Fail);

    expect($failed)->not->toBeEmpty()
        ->and($failed->pluck('detail')->implode(' '))->toContain('pooled_notes');
});

it('provisions the non-owner role idempotently — LOGIN, no SUPERUSER, no BYPASSRLS — and resets its password on rerun', function () {
    config(['beam.tenancy.pooled.rls_user' => ['username' => 'beam_tenancy_role_probe', 'password' => 'first']]);
    Artisan::registerCommand(app(Splicewire\Beam\Tenancy\Commands\PoolsRole::class));

    $this->artisan('splicewire:beam:tenancy:pools:role')->expectsOutputToContain('Created role beam_tenancy_role_probe')->assertSuccessful();
    $this->artisan('splicewire:beam:tenancy:pools:role')->expectsOutputToContain('Reset password of existing role')->assertSuccessful();

    $role = DB::selectOne('select rolcanlogin, rolsuper, rolbypassrls from pg_roles where rolname = ?', ['beam_tenancy_role_probe']);
    expect($role->rolcanlogin)->toBeTrue()->and($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

    DB::statement('drop role beam_tenancy_role_probe');
});

it('ends tenancy when a pooled request terminates — a second request with no tenant bound reads zero pool rows', function () {
    // pooled-storage 07 review: the sqlite EndTenancyOnTerminateTest proves the MECHANISM (a
    // RecordingBootstrapper reverts) but never a pooled tenant, and this package's own "unbound
    // connection" test only probed a hand-built config, never the real request lifecycle. This is
    // that case: the actual middleware, a real pooled tenant, real Postgres.
    $alpha = provisionPooled('alpha');
    $middleware = app(Splicewire\Beam\Tenancy\Http\Middleware\EndTenancyOnTerminate::class);

    tenancy()->initialize($alpha);
    DB::connection('tenant')->table('pooled_notes')->insert(['slug' => 'term-1', 'title' => 'x']);
    expect(tenancy()->initialized)->toBeTrue();

    // The framework's real terminate() call — what ends a request under Octane/FrankenPHP.
    $middleware->terminate(Illuminate\Http\Request::create('/'), new Illuminate\Http\Response);

    expect(tenancy()->initialized)->toBeFalse();

    // The NEXT request, in the same process, binds no tenant. Under the schema-per-tenant manager a
    // leaked frame reads the wrong tenant's rows silently; under pooled storage a leaked or unbound
    // frame reads ZERO rows, because the policy — not search_path — is what's doing the scoping.
    $unbound = $alpha->database()->connection();
    unset($unbound['session_settings']);
    config(['database.connections.term_unbound_probe' => $unbound]);

    try {
        expect(DB::connection('term_unbound_probe')->table('pool_default.pooled_notes')->where('slug', 'term-1')->count())->toBe(0);
    } finally {
        DB::purge('term_unbound_probe');
    }
});

it('names the missing command when the configured RLS role does not exist', function () {
    config(['beam.tenancy.pooled.rls_user' => ['username' => 'beam_tenancy_absent_role', 'password' => '']]);

    expect(fn () => app(PoolMigrator::class)->migrate('default'))
        ->toThrow(RuntimeException::class, 'pools:role');
});

it('deletes a leaving tenant\'s rows across a foreign key even when the parent table is met first', function () {
    $alpha = provisionPooled('alpha');
    $bravo = provisionPooled('bravo');

    foreach ([$alpha, $bravo] as $tenant) {
        tenancy()->initialize($tenant);
        $folder = (string) DB::connection('tenant')->table('pooled_a_folders')->insertGetId(['name' => 'f'], 'id');
        DB::connection('tenant')->table('pooled_b_items')->insert(['folder_id' => $folder]);
        tenancy()->end();
    }

    $alpha->database()->manager()->deleteDatabase($alpha);

    expect(collect(DB::select('select tenant_id from pool_default.pooled_a_folders'))->pluck('tenant_id')->all())->toBe(['bravo'])
        ->and(collect(DB::select('select tenant_id from pool_default.pooled_b_items'))->pluck('tenant_id')->all())->toBe(['bravo']);
});

it('refuses a pooled delete on a frame that is not the tenant\'s own non-owner, key-bound connection', function () {
    $alpha = provisionPooled('alpha');

    // A tenant marked pooled whose session-settings internal was lost: its connection keeps the
    // owner's credentials, which RLS does not scope with FORCE off.
    $alpha->setInternal('db_session_settings', null);
    $alpha->save();
    $stale = Tenant::find('alpha');

    expect(fn () => $stale->database()->manager()->deleteDatabase($stale))
        ->toThrow(RuntimeException::class, 'Refusing a pooled delete');
});
