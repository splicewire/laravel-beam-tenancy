<?php

namespace Splicewire\Beam\Tenancy\Tests\Doctor;

use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Tenancy\Doctor\BeamTenancyMigrationsAudit;
use Splicewire\Beam\Tenancy\Tests\TestCase;

/**
 * beam-tenancy's own operator check: its migrations must stay publish-only .stub files. Mirrors the
 * per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology`) —
 * a thin test wrapping a shared engine, declaring only "which audit is mine."
 */
class BeamTenancyMigrationsAuditTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_tenancy_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsAuditClass(): string
    {
        return BeamTenancyMigrationsAudit::class;
    }
}
