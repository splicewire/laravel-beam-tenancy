<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Tenancy\Data\TenantData;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Display\StatusManager;
use Splicewire\Beam\Workflows\Facades\Status;
use Stancl\Tenancy\Events\DatabaseCreated;
use Stancl\Tenancy\Events\DatabaseMigrated;

/**
 * owner-review-20261102 K-18a (research 18, D-18-a: (a) + the read guard from (c)).
 *
 * Every `tenants:migrate` fires stancl's `DatabaseMigrated` once per tenant, and the infra listener logged another
 * "Running migrations · running" into the tenant's FINISHED provisioning run, which nothing ever closed. On the live
 * flagship (2026-11-02) all 18 tenants were active and held 359 such rows; billing-demo's run was 13 steps, `complete`
 * on 2026-09-01, then 19 strays, so its operator page read "Running migrations — In progress", polled, and disabled
 * "Apply scaffold pack" (`isBusy`).
 *
 * Three claims, each tested here against the real stancl event wiring:
 *  - the infra listeners record only on a tenant that is not yet active (a re-migration is maintenance);
 *  - the rows already logged read finished: `isBusy` is false and the steps fold to done (the fold half is
 *    beam-workflows' `StatusStepData::fold`, same lane);
 *  - nothing else writes into a finished run either: maintenance on an active tenant (a pool move, a schema
 *    promotion, an isolated-database migration) opens its own run, so the read guard cannot hide one in flight.
 */
class ProvisioningRunStrayTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // This suite IS the stancl event wiring under test (the harness default is off).
        $app['config']->set('beam.tenancy.wire_stancl_events', true);
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(\Spatie\Activitylog\ActivitylogServiceProvider::class);
        config([
            'database.connections.central' => config('database.connections.testing'),
            'activitylog.activity_model' => CentralActivityLog::class,
            'activitylog.buffer.enabled' => false,
            'beam.workflows.status_log_name' => 'status',
        ]);
        Schema::connection('central')->create('activity_log', function (Blueprint $table) {
            $table->increments('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->string('event')->nullable();
            $table->timestamps();
        });
        // TenantData::project reads `domains` for primaryHost (the same table TenantResourceTest stands up).
        Schema::create('domains', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('tenant_id');
            $table->timestamps();
        });
        Status::swap(new StatusManager(new StatusEmitter(config(), app('events'))));
    }

    /** The 13 step refs of a finished provisioning run, the migrate step among them (billing-demo's shape). */
    private const STEPS = [
        'provisioning_create_database', 'provisioning_migrate_database', 'step_3', 'step_4', 'step_5', 'step_6',
        'step_7', 'step_8', 'step_9', 'step_10', 'step_11', 'step_12', 'step_13',
    ];

    private function provisioned(string $id): Tenant
    {
        $tenant = Tenant::create(['id' => $id, 'name' => ucfirst($id), 'slug' => $id]);
        foreach (self::STEPS as $step) {
            $tenant->markProvisioning($step, "Doing {$step}");
        }
        $tenant->markActive();

        return $tenant;
    }

    /** What the pre-fix listener wrote on every re-migration: a `running` under the finished run's id. */
    private function legacyStrays(Tenant $tenant, int $count): void
    {
        foreach (range(1, $count) as $_) {
            Status::running($tenant, 'Running migrations', ref: 'provisioning_migrate_database', runId: $tenant->status_run_id);
        }
    }

    public function test_a_re_migration_of_an_active_tenant_writes_nothing_into_its_finished_run(): void
    {
        $tenant = $this->provisioned('remigrated');
        $before = $tenant->statusTimeline()->count();

        event(new DatabaseMigrated($tenant));
        event(new DatabaseCreated($tenant));

        $this->assertSame(14, $before);
        $this->assertSame(14, $tenant->statusTimeline()->count());
        $this->assertSame('complete', $tenant->latestStatusEvent()->event);
        $this->assertFalse($tenant->fresh()->isBusy());
    }

    public function test_a_re_migration_of_a_failed_tenant_leaves_it_failed_and_not_busy(): void
    {
        // Codex K-18a: a failed tenant is not provisioning either. markProvisioning would mint a run, flip the
        // status back to `provisioning` and leave it busy with no pipeline to finish it.
        $tenant = Tenant::create(['id' => 'broken', 'name' => 'Broken', 'slug' => 'broken']);
        $tenant->markProvisioning('provisioning_create_database', 'Creating database');
        $tenant->markFailed('boom');
        $run = $tenant->status_run_id;

        event(new DatabaseMigrated($tenant));
        event(new DatabaseCreated($tenant));

        $fresh = $tenant->fresh();
        $this->assertSame('failed', $fresh->provisioning_status);
        $this->assertSame($run, $fresh->status_run_id);
        $this->assertSame(2, $tenant->statusTimeline()->count());
        $this->assertFalse($fresh->isBusy());
    }

    public function test_a_sub_part_terminal_does_not_end_the_run(): void
    {
        // Codex K-18a: ADR-0098 — a null ref is the whole run, a ref is a sub-part. A sub-part's `complete`
        // followed by more work is a run still in flight, not a stray.
        $tenant = Tenant::create(['id' => 'parts', 'name' => 'Parts', 'slug' => 'parts']);
        $tenant->markProvisioning('a', 'Doing a');
        $tenant->markActive();
        $tenant->markProvisioning('move_a', 'Moving a');
        Status::complete($tenant, 'Moved a', ref: 'move_a', runId: $tenant->status_run_id);
        $tenant->markProvisioning('move_b', 'Moving b');

        $this->assertTrue($tenant->fresh()->isBusy());
        $this->assertTrue(Tenant::with('statusEvents')->find('parts')->isBusy());
        $this->assertSame(
            [['move_a', 'done'], ['move_b', 'running']],
            array_map(fn ($step) => [$step->ref, $step->state], TenantData::project(Tenant::with(['domains', 'statusEvents'])->find('parts'))->steps),
        );
    }

    public function test_work_after_a_buffered_terminal_still_opens_its_own_run(): void
    {
        // Codex K-18a: with activitylog buffering on, a terminal event sits in the buffer until the job ends, so
        // the log cannot say the run closed. tower's MigrateTenantToIsolatedDatabase promotes a pooled tenant
        // (whose markActive() closes the run) and then carries on, after a refresh(); the closed run is on the row.
        $tenant = $this->provisioned('isolating');
        config(['activitylog.buffer.enabled' => true]);

        $tenant->markProvisioning('provision_isolated_database', 'Provisioning the isolated database destination');
        $promotionRun = $tenant->status_run_id;
        $tenant->markProvisioning('promote_create_schema', 'Creating schema');
        $tenant->markActive();
        $tenant->refresh();
        $tenant->markProvisioning('migrate_schema', 'Running tenant migrations on the isolated database');

        $this->assertNotSame($promotionRun, $tenant->status_run_id);
        $this->assertSame(14, $tenant->statusTimeline()->count()); // the buffered rows are not in the log yet
    }

    public function test_migrating_during_provisioning_still_records_the_step(): void
    {
        $tenant = Tenant::create(['id' => 'fresh', 'name' => 'Fresh', 'slug' => 'fresh']);

        event(new DatabaseCreated($tenant));
        event(new DatabaseMigrated($tenant));

        $events = $tenant->statusTimeline();
        $this->assertSame(['provisioning_create_database', 'provisioning_migrate_database'], $events->pluck('properties.ref')->all());
        $this->assertSame(['running', 'running'], $events->pluck('event')->all());
        $this->assertSame('provisioning', $tenant->fresh()->provisioning_status);
        $this->assertTrue($tenant->fresh()->isBusy());
    }

    public function test_the_strays_already_logged_read_finished_from_a_query_and_from_the_loaded_relation(): void
    {
        $tenant = $this->provisioned('billing-demo');
        $this->legacyStrays($tenant, 19);

        $this->assertSame(33, $tenant->statusTimeline()->count());
        $this->assertSame('running', $tenant->latestStatusEvent()->event);

        // Unloaded: answers from a query.
        $this->assertFalse($tenant->fresh()->isBusy());
        $this->assertFalse($tenant->fresh()->provisioningIsStalled());

        // Loaded (the list read's includes): answers from the relation, with no query.
        $loaded = Tenant::with(['domains', 'statusEvents'])->find('billing-demo');
        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();
        $busy = $loaded->isBusy();
        $queries = DB::connection('central')->getQueryLog();
        DB::connection('central')->disableQueryLog();
        $this->assertFalse($busy);
        $this->assertSame([], $queries);

        // The page's projection: not busy, and the 13 steps fold to done (no "Running migrations — In progress").
        $data = TenantData::project($loaded);
        $this->assertFalse($data->isBusy);
        $this->assertSame(
            array_map(fn (string $ref) => [$ref, 'done'], self::STEPS),
            array_map(fn ($step) => [$step->ref, $step->state], $data->steps),
        );
    }

    public function test_a_pack_apply_on_an_active_tenant_stays_busy_until_it_ends(): void
    {
        $tenant = $this->provisioned('packed');
        $this->legacyStrays($tenant, 2);

        $tenant->markPackApplyStarting('crm');
        $this->assertTrue($tenant->fresh()->isBusy());

        $tenant->markPackApplyComplete('crm');
        $this->assertFalse($tenant->fresh()->isBusy());
    }

    public function test_maintenance_on_an_active_tenant_opens_its_own_run_so_it_reads_busy_while_in_flight(): void
    {
        // The other markProvisioning callers (research 18 "Not measured"): TenantPoolMover, tower's
        // PromotePooledTenantToSchema and MigrateTenantToIsolatedDatabase each run on an ACTIVE tenant, log steps
        // through markProvisioning and close with markActive/markFailed. They used to write into the finished
        // provisioning run, which the read guard would now hide; they get a run of their own instead.
        $tenant = $this->provisioned('moving');
        $this->legacyStrays($tenant, 3);
        $provisioningRun = $tenant->status_run_id;

        $tenant->markProvisioning('pool_move_prepare_target', "Preparing pool 'b'");
        $tenant->markProvisioning('pool_move_copy', "Copying rows to pool 'b'");

        $moveRun = $tenant->fresh()->status_run_id;
        $this->assertNotSame($provisioningRun, $moveRun);
        $this->assertSame('active', $tenant->fresh()->provisioning_status);
        $this->assertTrue($tenant->fresh()->isBusy());
        $this->assertSame(
            [['pool_move_prepare_target', 'done'], ['pool_move_copy', 'running']],
            array_map(fn ($step) => [$step->ref, $step->state], TenantData::project(Tenant::with(['domains', 'statusEvents'])->find('moving'))->steps),
        );

        $tenant->markActive();
        $this->assertFalse($tenant->fresh()->isBusy());
        $this->assertSame($moveRun, $tenant->fresh()->status_run_id);
    }
}
