<?php

namespace Splicewire\Beam\Tenancy\Contracts;

/**
 * The lifecycle of an external domain provisioning reconcile. Subdomains under a
 * wildcard are Active the instant their binding exists; vanity domains pass through
 * Pending (awaiting CNAME propagation / cert issuance) before Active, or Failed.
 */
enum DomainProvisionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
}
