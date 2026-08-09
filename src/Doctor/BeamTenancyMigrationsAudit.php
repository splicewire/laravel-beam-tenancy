<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Tenancy\BeamMultiTenancyServiceProvider;

class BeamTenancyMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-tenancy';
    }

    protected function serviceProviderClass(): string
    {
        return BeamMultiTenancyServiceProvider::class;
    }
}
