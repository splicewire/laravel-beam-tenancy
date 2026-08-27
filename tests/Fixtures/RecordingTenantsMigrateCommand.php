<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

use Illuminate\Console\Command;

/**
 * Stands in for stancl's `tenants:migrate`, which this harness does not boot.
 *
 * It exists to answer one question the estate cares about more than whether the migration ran:
 * **was it SCOPED?** A bare `tenants:migrate` fans out across every tenant, which is a documented
 * hazard, so the recorded `--tenants` value is the assertion.
 */
class RecordingTenantsMigrateCommand extends Command
{
    protected $signature = 'tenants:migrate {--tenants=*}';

    /** @var list<list<string>> One entry per invocation: the `--tenants` value it was given. */
    public static array $calls = [];

    public static function reset(): void
    {
        static::$calls = [];
    }

    public function handle(): int
    {
        static::$calls[] = array_map(strval(...), (array) $this->option('tenants'));

        return self::SUCCESS;
    }
}
