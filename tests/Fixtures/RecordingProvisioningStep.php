<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

/**
 * Sentinel pipeline step for the dispatcher tests (ADR-0003): records which tenant it ran for, so a
 * test can assert the TenantCreated listener resolved and executed the registered pipeline — at fire
 * time, through the real JobPipeline dispatch.
 */
class RecordingProvisioningStep
{
    /** @var list<string> */
    public static array $ran = [];

    public static function reset(): void
    {
        static::$ran = [];
    }

    // stancl's JobPipeline hands the passable to the CONSTRUCTOR (the shape of stancl's own
    // Jobs\DeleteDatabase); handle() takes nothing.
    public function __construct(public mixed $tenant = null) {}

    public function handle(): void
    {
        static::$ran[] = (string) $this->tenant->getTenantKey();
    }
}
