<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Counts `bootstrap()`/`revert()` so a test can assert the tenancy lifecycle fired, without needing a
 * real database manager. The package harness runs sqlite with `tenancy.database.managers => []`, so
 * stancl's `DatabaseTenancyBootstrapper` cannot run here — and asserting the revert *mechanism* is
 * strictly better than asserting one bootstrapper's side effect anyway: it is what every bootstrapper,
 * present and future, depends on.
 */
class RecordingBootstrapper implements TenancyBootstrapper
{
    public static int $bootstrapped = 0;

    public static int $reverted = 0;

    public static function reset(): void
    {
        static::$bootstrapped = 0;
        static::$reverted = 0;
    }

    public function bootstrap(Tenant $tenant): void
    {
        static::$bootstrapped++;
    }

    public function revert(): void
    {
        static::$reverted++;
    }
}
