<?php

namespace Splicewire\Beam\Tenancy\Domains;

use Splicewire\Beam\Tenancy\Contracts\DomainProvisionStatus;
use Splicewire\Beam\Tenancy\Contracts\TenantDnsProvider;
use Splicewire\Beam\Tenancy\Contracts\TenantEdgeProvider;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Default DNS + edge provider. Reports every Tenant Domain Active and no-ops
 * teardown. This is truthful, not a stub: production serves tenant subdomains on a
 * wildcard `*.app` DNS record plus a wildcard origin cert (relaunch issue 03), so a
 * subdomain is genuinely live the instant its binding exists — no per-tenant
 * Cloudflare or Plesk call is made. Real drivers land when vanity domains do, as a
 * config flip in config/tenancy.php. See app ADR-0025.
 */
class NullDomainProvider implements TenantDnsProvider, TenantEdgeProvider
{
    public function ensure(Domain $domain): DomainProvisionStatus
    {
        return DomainProvisionStatus::Active;
    }

    public function remove(Domain $domain): void
    {
        //
    }
}
