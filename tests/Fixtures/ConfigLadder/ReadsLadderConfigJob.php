<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** Fixture (config-sections ticket 07): a queued job that records the `cs07` config it ran with, centrally. */
class ReadsLadderConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $label) {}

    public function handle(): void
    {
        DB::connection('central')->table('cs07_job_reads')->insert([
            'label' => $this->label,
            'tenant' => function_exists('tenant') && tenant() !== null ? (string) tenant()->getTenantKey() : null,
            'review' => json_encode(config('cs07.review')),
            'theme' => (string) config('cs07.theme'),
        ]);
    }
}
