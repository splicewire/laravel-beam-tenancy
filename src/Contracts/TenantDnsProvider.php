<?php

namespace Splicewire\Beam\Tenancy\Contracts;

use Stancl\Tenancy\Database\Models\Domain;

/**
 * DNS authority for a Tenant Domain — makes the name resolve (Cloudflare today;
 * could be Route53). For a vanity host the customer owns DNS, so this verifies a
 * CNAME points at us rather than creating a record. See app ADR-0025.
 */
interface TenantDnsProvider
{
    /** Idempotent reconcile toward a resolvable name. */
    public function ensure(Domain $domain): DomainProvisionStatus;

    /** Idempotent teardown of any record this provider created. */
    public function remove(Domain $domain): void;
}
