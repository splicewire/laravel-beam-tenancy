<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Splicewire\Beam\Tenancy\Http\Middleware\EndTenancyOnTerminate;

/**
 * The OFF path of `beam.tenancy.end_on_terminate`.
 *
 * Its own suite covers the on path only, and a config gate whose off branch is never exercised is a
 * branch nobody has run — a host that sets it false is the first person to find out what happens.
 *
 * Worth its own test class rather than a case in the sibling file, because the gate is read during
 * `packageBooted()`. Flipping config after the app has booted changes nothing, so the only honest way
 * to test the off path is to boot an app with it off — which is a per-class concern in testbench.
 *
 * ⚠️ Recorded because it is the more interesting half: the middleware's config call passes a `true`
 * DEFAULT, and that default is **dead code** — `config/beam/tenancy.php` sets the key outright, so the
 * default argument is unreachable at any host that publishes the config. Flipping it in the source
 * therefore leaves the suite green, which is why this file asserts against the CONFIG VALUE rather
 * than against the default.
 */
class EndTenancyTerminatorDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.tenancy.end_on_terminate', false);
    }

    public function test_a_host_that_declines_the_terminator_does_not_get_the_middleware(): void
    {
        $this->assertFalse(
            $this->app->make(HttpKernel::class)->hasMiddleware(EndTenancyOnTerminate::class),
            'The gate is off, so nothing should have been pushed onto the global stack.',
        );
    }
}
