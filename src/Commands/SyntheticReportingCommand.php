<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;

/**
 * Show or flip whether reporting leaves Synthetic Tenants out. The command-line twin of the
 * `tenants/synthetic-reporting` operation; both go through {@see SyntheticReporting::set()}, so both are logged.
 */
class SyntheticReportingCommand extends Command
{
    protected $signature = 'splicewire:beam:tenancy:synthetic-reporting
        {--exclude : Leave Synthetic Tenants out of reporting}
        {--include : Keep Synthetic Tenants in reporting}
        {--clear : Clear the runtime override, so config answers again}';

    protected $description = 'Show or set whether reporting excludes Synthetic Tenants (a runtime override, no redeploy)';

    public function handle(SyntheticReporting $reporting): int
    {
        $chosen = array_keys(array_filter([
            'exclude' => (bool) $this->option('exclude'),
            'include' => (bool) $this->option('include'),
            'clear' => (bool) $this->option('clear'),
        ]));

        if (count($chosen) > 1) {
            $this->error('Pass at most one of --exclude, --include and --clear.');

            return self::INVALID;
        }

        $state = $chosen === []
            ? $reporting->state()
            : $reporting->set(match ($chosen[0]) {
                'exclude' => true,
                'include' => false,
                'clear' => null,
            });

        $this->line(sprintf(
            'Synthetic tenants are %s reporting (source: %s; config says %s).',
            $state->excludes ? 'EXCLUDED from' : 'INCLUDED in',
            $state->source,
            $state->configured ? 'exclude' : 'include',
        ));

        return self::SUCCESS;
    }
}
