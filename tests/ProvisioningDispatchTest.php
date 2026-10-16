<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Splicewire\Beam\Tenancy\Provisioning\TenantProvisioningStepRegistry;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\RecordingProvisioningStep;
use Splicewire\Beam\Tenancy\Tests\Fixtures\SecondRecordingProvisioningStep;

/**
 * The package-owned TenantCreated dispatch (ADR-0003), tested at its interface: register a pipeline,
 * create a tenant, the steps run. The registrations happen INSIDE the tests — after every provider
 * has booted — which is the fire-time-resolution claim itself: the flagship's old boot-time
 * `stepsFor()` read could never see these.
 */
class ProvisioningDispatchTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The harness default is off (a testbench app is not a stancl host); this suite IS the
        // wiring under test. Routing wiring stays off — separate concern.
        $app['config']->set('beam.tenancy.wire_stancl_events', true);
        // Inline execution: shouldBeQueued(true) + the sync driver runs the pipeline in-process.
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The estate's testbench rule (see EndTenancyOnTerminateTest): bind Tenancy singleton.
        $this->app->singleton(\Stancl\Tenancy\Tenancy::class);
        $this->app->bind(\Stancl\Tenancy\Contracts\Tenant::class, fn ($app) => $app[\Stancl\Tenancy\Tenancy::class]->tenant);

        RecordingProvisioningStep::reset();
        SecondRecordingProvisioningStep::reset();
    }

    public function test_a_registered_pipeline_runs_on_tenant_created(): void
    {
        $this->app->make(TenantProvisioningStepRegistry::class)
            ->register('default', [RecordingProvisioningStep::class]);

        $tenant = Tenant::create(['id' => 'dispatch-a', 'name' => 'Dispatch A', 'slug' => 'dispatch-a']);

        $this->assertSame([(string) $tenant->getTenantKey()], RecordingProvisioningStep::$ran);
    }

    public function test_no_registered_pipeline_is_a_quiet_no_op(): void
    {
        // No registration at all: the listener must neither throw (the old boot-time read died on
        // `stepsFor()`'s miss) nor run anything.
        $tenant = Tenant::create(['id' => 'dispatch-b', 'name' => 'Dispatch B', 'slug' => 'dispatch-b']);

        $this->assertTrue($tenant->exists);
        $this->assertSame([], RecordingProvisioningStep::$ran);
    }

    public function test_a_superseding_registration_wins_at_fire_time(): void
    {
        $registry = $this->app->make(TenantProvisioningStepRegistry::class);
        $registry->register('default', [RecordingProvisioningStep::class]);
        // OnKeyDuplicate::Supersede — the registry's own override path IS the dispatcher's.
        $registry->register('default', [SecondRecordingProvisioningStep::class]);

        Tenant::create(['id' => 'dispatch-c', 'name' => 'Dispatch C', 'slug' => 'dispatch-c']);

        $this->assertSame([], RecordingProvisioningStep::$ran);
        $this->assertCount(1, SecondRecordingProvisioningStep::$ran);
    }

    public function test_changing_the_escape_hatch_after_boot_does_not_unregister_the_listener(): void
    {
        // This case re-enters the app with the gate off, as a hand-wiring host (standwell) would.
        $this->app['config']->set('beam.tenancy.wire_stancl_events', false);
        // The listener was registered at boot (gate was on then); flipping config now does not
        // unregister it — the gate is a boot-time decision. Assert THAT, so nobody mistakes the key
        // for a runtime switch: the pipeline still runs for this app instance.
        $this->app->make(TenantProvisioningStepRegistry::class)
            ->register('default', [RecordingProvisioningStep::class]);

        Tenant::create(['id' => 'dispatch-d', 'name' => 'Dispatch D', 'slug' => 'dispatch-d']);

        $this->assertCount(1, RecordingProvisioningStep::$ran);
    }
}
