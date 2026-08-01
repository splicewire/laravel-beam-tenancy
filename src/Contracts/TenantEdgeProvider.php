<?php

namespace Splicewire\Beam\Tenancy\Contracts;

use Stancl\Tenancy\Database\Models\Domain;

/**
 * Edge / vhost / TLS termination for a Tenant Domain — makes the origin serve the
 * host and present a valid cert (Plesk vhost + LE cert today; could be a load
 * balancer, Caddy on-demand-TLS, or a Cloudflare-proxied record later). See app
 * ADR-0025.
 */
interface TenantEdgeProvider
{
    /** Idempotent reconcile toward a serving, TLS-terminated edge. */
    public function ensure(Domain $domain): DomainProvisionStatus;

    /** Idempotent teardown of any vhost/cert this provider created. */
    public function remove(Domain $domain): void;
}
