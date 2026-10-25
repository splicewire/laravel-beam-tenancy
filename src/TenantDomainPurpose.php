<?php

namespace Splicewire\Beam\Tenancy;

/** Which tenant surface a domain names. Each tenant may have one primary of each purpose. */
enum TenantDomainPurpose: string
{
    case App = 'app';

    case Site = 'site';
}
