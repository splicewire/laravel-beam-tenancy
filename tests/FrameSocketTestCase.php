<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Rushing\DataNav\ServiceProvider as DataNavServiceProvider;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\JsonNs\Laravel\JsonNsServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;

/**
 * The sqlite harness plus the FRAME SOCKET — `GET frame/resources/{key}` and `…/summary` served for
 * real, over beam-core's own runtime (realm-dashboards 06a review).
 *
 * The base {@see TestCase} deliberately boots neither frame nor beam: this package's unit tests bind the
 * two collaborators `ScopedIndexQuery` needs by hand, which is enough to ASK a provider for its figures
 * but not enough to ask the INDEX what it would have listed. The scope-leak property is a statement
 * about both reads at once — "this figure equals that actor's index total" — so it cannot be asserted
 * against a hand-computed twin of one of them; it needs the other read, through the transport that
 * serves it.
 *
 * Providers are named rather than discovered (testbench does not auto-discover), in beam's own declared
 * order: beam-core's dependencies DOWN, frame above, and this package's provider last so its discovery
 * lands in beam's registry rather than in a throwaway.
 */
abstract class FrameSocketTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            FrameServiceProvider::class,
            \Splicewire\Beam\BeamServiceProvider::class,
            ActivitylogServiceProvider::class,
            VersioningServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            DataNavServiceProvider::class,
            JsonNsServiceProvider::class,
            DataFiltersServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The frame routes carry the host's own middleware stack by default; a package harness has no
        // session or auth middleware to satisfy, and the gate under test is frame's resource gate, not
        // the pipeline in front of it.
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The base harness has no `domains`: nothing in it reads a tenant through the `tenants`
        // declaration, whose `includes:` eager-load the relation and whose row projection resolves
        // `primaryHost` off it. An index read does both, so the table the declaration depends on has to
        // exist — mirrors the stub's shape (FKs omitted; sqlite in-memory).
        Schema::create('domains', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('tenant_id');
            $table->timestamps();
        });

        // Same reason one relation over: the declaration's second include is `statusEvents`, the Display
        // timeline, which is activitylog-backed and pinned to the `central` connection. `activity_log` is
        // one SHARED migration run in both passes, so each connection holds its own copy; only the
        // central one is read from here.
        Schema::connection('central')->create('activity_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('log_name')->nullable();
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('event')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }
}
