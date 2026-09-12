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
function provisionPooled(string $id): Tenant
{
    $tenant = Tenant::create(['id' => $id, 'name' => ucfirst($id), 'slug' => $id]);
    $tenant->markPooled('default')->save();
    // What stancl's CreateDatabase job does, minus the queue.
    $tenant->database()->makeCredentials();
    $tenant->database()->manager()->createDatabase($tenant);

    return Tenant::find($id);
}

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

    // The markers audit passes too: both tenants carry their own key and the pool schema.
    expect(app(Splicewire\Beam\Tenancy\Doctor\PooledTenantMarkersAudit::class)->run()[0]->status)->toBe(Rushing\Doctor\DoctorStatus::Pass);

    // Break one invariant the way an operator could: RLS switched off on one pool table.
    DB::statement('alter table pool_default.pooled_notes disable row level security');
    $failed = collect(app(Splicewire\Beam\Tenancy\Doctor\PooledStorageAudit::class)->run())
        ->filter(fn ($f) => $f->status === Rushing\Doctor\DoctorStatus::Fail);

    expect($failed)->not->toBeEmpty()
        ->and($failed->pluck('detail')->implode(' '))->toContain('pooled_notes');
});
