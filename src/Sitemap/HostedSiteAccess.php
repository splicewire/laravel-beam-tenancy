<?php

namespace Splicewire\Beam\Tenancy\Sitemap;

use Splicewire\Beam\Tenancy\Tenant;

/** The host-owned plan decision that allows a tenant's public URLs to use its Site Host. */
interface HostedSiteAccess
{
    public function allows(Tenant $tenant): bool;
}
