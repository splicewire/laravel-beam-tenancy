<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Rushing\SaloonKernel\Contracts\KernelContextBridge;

/**
 * ADR-0004's escape hatch is a boot-time gate (register phase reads it once), so it is asserted the
 * honest way: a whole app booted with the key off. The harness boots no saloon-kernel provider, so
 * with this package's bind skipped the contract is simply unbound — which is the claim.
 */
class KernelContextBridgeEscapeHatchTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.tenancy.bind_kernel_context_bridge', false);
    }

    public function test_the_gate_skips_the_binding(): void
    {
        if (! interface_exists(KernelContextBridge::class)) {
            $this->markTestSkipped('rushing/laravel-saloon-kernel not installed (require-dev) — run composer update');
        }

        $this->assertFalse($this->app->bound(KernelContextBridge::class));
    }
}
