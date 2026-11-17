<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** Fixture (config-sections ticket 15): a queued job that records the chat default model it ran with, centrally. */
class ReadsDefaultModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $label) {}

    public function handle(): void
    {
        DB::connection('central')->table('cs15_job_reads')->insert([
            'label' => $this->label,
            'tenant' => function_exists('tenant') && tenant() !== null ? (string) tenant()->getTenantKey() : null,
            'default_model' => (string) config('app.chat.default_model'),
        ]);
    }
}
