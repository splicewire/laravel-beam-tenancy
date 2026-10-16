<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Rushing\SaloonKernel\Contracts\KernelContextBridge;
use Splicewire\Beam\Tenancy\Loopback\TenancyContextBridge;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * ADR-0004: where the saloon-kernel contract is loaded, this package's bridge supersedes the
 * transport's Null default, and capture/restore is a total inverse. The contract arrives via
 * require-dev — exactly the soft edge the registration guards on.
 */
class KernelContextBridgeBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! interface_exists(KernelContextBridge::class)) {
            $this->markTestSkipped('rushing/laravel-saloon-kernel not installed (require-dev) — run composer update');
        }

        // The estate's testbench rule (see EndTenancyOnTerminateTest): bind Tenancy singleton.
        $this->app->singleton(\Stancl\Tenancy\Tenancy::class);
        $this->app->bind(\Stancl\Tenancy\Contracts\Tenant::class, fn ($app) => $app[\Stancl\Tenancy\Tenancy::class]->tenant);
    }

    public function test_the_tenancy_bridge_supersedes_the_null_default(): void
    {
        $this->assertInstanceOf(TenancyContextBridge::class, $this->app->make(KernelContextBridge::class));
    }

    public function test_capture_restore_is_a_total_inverse(): void
    {
        $bridge = $this->app->make(KernelContextBridge::class);
        $t1 = Tenant::create(['id' => 'bridge-a', 'name' => 'Bridge A', 'slug' => 'bridge-a']);
        $t2 = Tenant::create(['id' => 'bridge-b', 'name' => 'Bridge B', 'slug' => 'bridge-b']);

        try {
            // A captured tenant re-initializes after the nested request switched things around.
            tenancy()->initialize($t1);
            $captured = $bridge->capture();
            tenancy()->initialize($t2); // the nested request re-resolved tenancy
            $bridge->restore($captured);
            $this->assertTrue((bool) tenancy()->initialized);
            $this->assertSame('bridge-a', (string) tenant()->getTenantKey());

            // A captured null ENDS whatever the nested request left initialized.
            tenancy()->end();
            $capturedNull = $bridge->capture();
            $this->assertNull($capturedNull);
            tenancy()->initialize($t2);
            $bridge->restore($capturedNull);
            $this->assertFalse((bool) tenancy()->initialized);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

}
