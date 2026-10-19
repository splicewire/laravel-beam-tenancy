<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Rushing\SaloonKernel\Contracts\KernelContextBridge;

/**
 * ADR-0004's escape hatch is a boot-time gate: the provider reads it ONCE, at register — which in
 * this harness happens BEFORE `defineEnvironment` lands (probed 2026-10-17: register saw true while
 * the test body saw false, so a config-set here can never exercise the gate; until the kernel
 * became installable this test always skipped, so the gap was invisible). The off-world is
 * therefore built the way a host builds it: the env var the config default reads, planted in
 * $_SERVER before the app exists — the same lane TlsVerifyDefaultTest proved in laravel-connector.
 *
 * The harness registers no saloon-kernel provider (only this package's), so with the supersession
 * skipped the contract is simply UNBOUND here; in a real host the kernel's own `bindIf` default
 * (`NullContextBridge`) stands instead. Both are the same claim: gate off, this package binds
 * nothing.
 */
class KernelContextBridgeEscapeHatchTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['BEAM_TENANCY_BIND_KERNEL_CONTEXT_BRIDGE'] = 'false';
        $_ENV['BEAM_TENANCY_BIND_KERNEL_CONTEXT_BRIDGE'] = 'false';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['BEAM_TENANCY_BIND_KERNEL_CONTEXT_BRIDGE'], $_ENV['BEAM_TENANCY_BIND_KERNEL_CONTEXT_BRIDGE']);

        parent::tearDown();
    }

    public function test_the_gate_skips_the_binding(): void
    {
        if (! interface_exists(KernelContextBridge::class)) {
            $this->markTestSkipped('rushing/laravel-saloon-kernel not installed (require-dev) — run composer update');
        }

        $this->assertFalse(
            (bool) $this->app['config']->get('beam.tenancy.bind_kernel_context_bridge'),
            'the planted env var must reach the merged config, or this test is asserting nothing',
        );
        $this->assertFalse($this->app->bound(KernelContextBridge::class));
    }
}
