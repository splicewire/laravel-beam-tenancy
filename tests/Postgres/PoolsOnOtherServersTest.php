<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Tenancy\Commands\PoolsMove;
use Splicewire\Beam\Tenancy\Doctor\PooledStorageAudit;
use Splicewire\Beam\Tenancy\Doctor\PooledTenantMarkersAudit;
use Splicewire\Beam\Tenancy\Pools\PoolMigrator;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Postgres\PostgresTestCase;

/**
 * pooled-storage ticket 13 — pools on other database servers, and moving a tenant between pools. The
 * "remote" pool lives in a second database on the test server (PostgresTestCase::remoteDatabase()), so
 * every read and write proves which catalogue it reached.
 */
function provisionPooledInto(string $id, string $pool): Tenant
{
    $tenant = Tenant::create(['id' => $id, 'name' => ucfirst($id), 'slug' => $id]);
    $tenant->markPooled($pool)->save();
    $tenant->database()->makeCredentials();
    $tenant->database()->manager()->createDatabase($tenant);

    return Tenant::find($id);
}

function writeNoteWithTag(Tenant $tenant, string $slug): void
{
    tenancy()->initialize($tenant);
    $noteId = (string) DB::connection('tenant')->table('pooled_notes')->insertGetId(['slug' => $slug, 'title' => $slug], 'id');
    DB::connection('tenant')->table('pooled_0_note_tags')->insert(['note_id' => $noteId, 'tag' => 'x']);
    tenancy()->end();
}

it('provisions a pooled tenant straight into a pool on another server, and its frame reads and writes there', function () {
    $alpha = provisionPooledInto('alpha', 'remote');

    expect($alpha->tenancy_db_connection)->toBe(PostgresTestCase::REMOTE_CONNECTION)
        ->and($alpha->database()->getName())->toBe('pool_remote');

    writeNoteWithTag($alpha, 'far');

    tenancy()->initialize($alpha);
    expect(DB::connection('tenant')->selectOne('select current_database() as d')->d)->toBe(PostgresTestCase::remoteDatabase())
        ->and(DB::connection('tenant')->selectOne('select current_user as u')->u)->toBe(PostgresTestCase::RLS_ROLE)
        ->and(DB::connection('tenant')->table('pooled_notes')->count())->toBe(1);
    tenancy()->end();

    expect(DB::connection(PostgresTestCase::REMOTE_CONNECTION)->table('pool_remote.pooled_notes')->where('tenant_id', 'alpha')->count())->toBe(1)
        ->and(DB::selectOne("select count(*) as n from information_schema.schemata where schema_name = 'pool_remote'")->n)->toBe(0, 'nothing landed on the central database');
});

it('moves a tenant from a central pool to a pool on another server, across a child table that sorts before its parent', function () {
    $alpha = provisionPooledInto('alpha', 'default');
    $bravo = provisionPooledInto('bravo', 'default');
    $charlie = provisionPooledInto('charlie', 'remote');
    writeNoteWithTag($alpha, 'a-1');
    writeNoteWithTag($alpha, 'a-2');
    writeNoteWithTag($bravo, 'b-1');
    writeNoteWithTag($charlie, 'c-1');

    Artisan::registerCommand(app(PoolsMove::class));
    $this->artisan('splicewire:beam:tenancy:pools:move', ['tenant' => 'alpha', 'pool' => 'remote'])
        ->expectsOutputToContain("moved from pool 'default' to pool 'remote'")
        ->assertSuccessful();

    $moved = Tenant::find('alpha');
    expect($moved->pool)->toBe('remote')
        ->and($moved->tenancy_db_connection)->toBe(PostgresTestCase::REMOTE_CONNECTION)
        ->and($moved->tenancy_db_name)->toBe('pool_remote')
        ->and($moved->write_blocked_at)->toBeNull();

    tenancy()->initialize($moved);
    expect(DB::connection('tenant')->selectOne('select current_database() as d')->d)->toBe(PostgresTestCase::remoteDatabase())
        ->and(DB::connection('tenant')->table('pooled_notes')->orderBy('slug')->pluck('slug')->all())->toBe(['a-1', 'a-2'])
        ->and(DB::connection('tenant')->table('pooled_0_note_tags')->count())->toBe(2);
    tenancy()->end();

    // The central pool keeps only bravo; alpha's rows are gone from it.
    expect(collect(DB::select('select tenant_id from pool_default.pooled_notes'))->pluck('tenant_id')->all())->toBe(['bravo'])
        ->and(collect(DB::select('select tenant_id from pool_default.pooled_0_note_tags'))->pluck('tenant_id')->all())->toBe(['bravo']);

    // The tenant already in the target pool keeps exactly its own rows: the target-side delete and
    // copy were scoped to alpha.
    expect(collect(DB::connection(PostgresTestCase::REMOTE_CONNECTION)->select('select tenant_id, slug from pool_remote.pooled_notes order by slug'))->map(fn ($r) => $r->tenant_id.':'.$r->slug)->all())
        ->toBe(['alpha:a-1', 'alpha:a-2', 'charlie:c-1']);

    // The doctor, across both servers — and it must actually have measured both.
    $findings = app(PooledStorageAudit::class)->run();
    $fails = fn (array $findings) => collect($findings)->filter(fn ($f) => $f->status === DoctorStatus::Fail)->map(fn ($f) => $f->check.': '.$f->detail)->values()->all();
    expect($fails(app(PooledTenantMarkersAudit::class)->run()))->toBe([])
        ->and($fails($findings))->toBe([])
        ->and(collect($findings)->contains(fn ($f) => $f->conclusive && str_contains($f->detail, 'pool_default')))->toBeTrue('central server measured')
        ->and(collect($findings)->contains(fn ($f) => $f->conclusive && str_contains($f->detail, 'pool_remote')))->toBeTrue('remote server measured');
});

it('clears rows an earlier failed attempt left in the target pool instead of duplicating them', function () {
    $alpha = provisionPooledInto('alpha', 'default');
    writeNoteWithTag($alpha, 'a-1');
    writeNoteWithTag(provisionPooledInto('charlie', 'remote'), 'c-1');

    // A stale alpha row already in the target pool (as the owner, so RLS does not stop the plant).
    DB::connection(PostgresTestCase::REMOTE_CONNECTION)->table('pool_remote.pooled_notes')->insert(['slug' => 'stale', 'title' => 'stale', 'tenant_id' => 'alpha']);

    Artisan::registerCommand(app(PoolsMove::class));
    $this->artisan('splicewire:beam:tenancy:pools:move', ['tenant' => 'alpha', 'pool' => 'remote'])->assertSuccessful();

    expect(DB::connection(PostgresTestCase::REMOTE_CONNECTION)->table('pool_remote.pooled_notes')->where('tenant_id', 'alpha')->pluck('slug')->all())->toBe(['a-1'])
        ->and(DB::connection(PostgresTestCase::REMOTE_CONNECTION)->table('pool_remote.pooled_notes')->where('tenant_id', 'charlie')->pluck('slug')->all())->toBe(['c-1']);
});

it('leaves an excluded, pool-level table alone on a move and on a pooled delete', function () {
    config(['postgres-rls.exclude' => ['migrations', 'pooled_shared_settings']]);
    $alpha = provisionPooledInto('alpha', 'default');
    $bravo = provisionPooledInto('bravo', 'default');
    writeNoteWithTag($alpha, 'a-1');
    DB::table('pool_default.pooled_shared_settings')->insert([['name' => 'x', 'value' => '1'], ['name' => 'y', 'value' => '2']]);

    Artisan::registerCommand(app(PoolsMove::class));
    $this->artisan('splicewire:beam:tenancy:pools:move', ['tenant' => 'alpha', 'pool' => 'remote'])->assertSuccessful();
    $bravo->database()->manager()->deleteDatabase($bravo);

    expect(DB::table('pool_default.pooled_shared_settings')->count())->toBe(2)
        ->and(DB::connection(PostgresTestCase::REMOTE_CONNECTION)->table('pool_remote.pooled_shared_settings')->count())->toBe(0);
});

it('keeps the old rows and fails when rows were written to the old pool during the move', function () {
    $alpha = provisionPooledInto('alpha', 'default');
    writeNoteWithTag($alpha, 'a-1');

    $mover = new class(app(Splicewire\Beam\Tenancy\Pools\PoolRegistry::class), app(PoolMigrator::class)) extends Splicewire\Beam\Tenancy\Pools\TenantPoolMover
    {
        protected function cleanupSource(Illuminate\Database\Connection $source, array $tables, array $moved): void
        {
            // A queued job still in alpha's old frame writes after the copy.
            $source->table('pooled_notes')->insert(['slug' => 'late', 'title' => 'late']);

            parent::cleanupSource($source, $tables, $moved);
        }
    };

    expect(fn () => $mover->move(Tenant::find('alpha'), 'remote'))->toThrow(RuntimeException::class, 'rows were written during the move');

    expect(Tenant::find('alpha')->pool)->toBe('remote', 'the cutover had happened')
        ->and(DB::table('pool_default.pooled_notes')->where('tenant_id', 'alpha')->orderBy('slug')->pluck('slug')->all())->toBe(['a-1', 'late']);
});

it('refuses a move into the same pool, and a tenant that is not pooled', function () {
    provisionPooledInto('alpha', 'default');
    Tenant::create(['id' => 'plain', 'name' => 'Plain', 'slug' => 'plain']);
    Artisan::registerCommand(app(PoolsMove::class));

    $this->artisan('splicewire:beam:tenancy:pools:move', ['tenant' => 'alpha', 'pool' => 'default'])
        ->expectsOutputToContain('already in pool')->assertFailed();
    $this->artisan('splicewire:beam:tenancy:pools:move', ['tenant' => 'plain', 'pool' => 'remote'])
        ->expectsOutputToContain('not on Pooled storage')->assertFailed();
});
