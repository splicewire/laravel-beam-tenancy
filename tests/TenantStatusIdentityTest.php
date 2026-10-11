<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Display\StatusManager;
use Splicewire\Beam\Workflows\Facades\Status;

beforeEach(function () {
    $this->app->register(ActivitylogServiceProvider::class);
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
    Status::swap(new StatusManager(new StatusEmitter(config(), app('events'))));
});

it('persists canonical tenant identity on real running and complete transitions', function (string $operation) {
    $tenant = Tenant::create(['id' => 'tenant-identity', 'name' => 'Beam Demo', 'slug' => 'beam_demo']);

    if ($operation === 'provisioning') {
        $tenant->markProvisioning('database', 'Creating database');
        expect($tenant->fresh()->provisioning_status)->toBe('provisioning');
        $tenant->markActive();
        expect($tenant->fresh()->provisioning_status)->toBe('active');
    } else {
        $tenant->provisioning_status = 'active';
        $tenant->save();
        $tenant->markPackApplyStarting('starter');
        $tenant->markPackApplyComplete('starter');
        expect($tenant->fresh()->provisioning_status)->toBe('active');
    }

    $events = $tenant->statusTimeline();
    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->properties->get('tenant_name'))->toBe('Beam Demo')
            ->and($event->properties->get('tenant_slug'))->toBe('beam_demo')
            ->and($event->subject_id)->toBe($tenant->getKey())
            ->and($event->subject_type)->toBe($tenant->getMorphClass())
            ->and($event->properties->get('run_id'))->toBe($tenant->status_run_id);
    }
    expect($events->pluck('event')->all())->toBe(['running', 'complete'])
        ->and($events->pluck('properties.state')->all())->toBe(['running', 'complete'])
        ->and($events->first()->properties->get('ref'))->toBe($operation === 'provisioning' ? 'database' : 'pack:starter')
        ->and($events->last()->properties->get('ref'))->toBeNull()
        ->and($events->first()->description)->toBe($operation === 'provisioning' ? 'Creating database' : "Applying scaffold pack 'starter'")
        ->and($events->last()->description)->toBe($operation === 'provisioning' ? 'Provisioning complete.' : "Applied scaffold pack 'starter'");
})->with(['provisioning', 'pack']);

it('adds identity without replacing existing status properties', function () {
    $tenant = Tenant::create(['id' => 'tenant-payload', 'name' => 'Beam Demo', 'slug' => 'beam_demo']);
    activity('status')->performedOn($tenant)->event('running')
        ->withProperties(['n' => 7, 'status' => 'running', 'progress' => ['done' => 2, 'total' => 7]])
        ->log('Working');

    expect($tenant->statusTimeline()->sole()->properties->all())->toBe([
        'n' => 7,
        'status' => 'running',
        'progress' => ['done' => 2, 'total' => 7],
        'tenant_name' => 'Beam Demo',
        'tenant_slug' => 'beam_demo',
    ]);
});

it('uses the configured status channel', function () {
    config(['beam.workflows.status_log_name' => 'tenant-status']);
    $tenant = Tenant::create(['id' => 'tenant-channel', 'name' => 'Beam Demo', 'slug' => 'beam_demo']);
    Status::running($tenant, 'Working');
    Status::complete($tenant, 'Done');

    expect($tenant->statusTimeline()->pluck('properties.tenant_name')->all())->toBe(['Beam Demo', 'Beam Demo'])
        ->and($tenant->statusTimeline()->pluck('log_name')->all())->toBe(['tenant-status', 'tenant-status']);
});

it('leaves other activity channels and status events unchanged', function () {
    $tenant = Tenant::create(['id' => 'tenant-other', 'name' => 'Beam Demo', 'slug' => 'beam_demo']);
    foreach ([['audit', 'running'], ['status', 'failed']] as [$channel, $event]) {
        $activity = activity($channel)->performedOn($tenant)->event($event)
            ->withProperties(['n' => 3])->log('Unchanged');
        expect($activity->fresh()->properties->all())->toBe(['n' => 3]);
    }
});
