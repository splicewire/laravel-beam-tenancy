<?php

namespace Splicewire\Beam\Tenancy;

enum TenantProvisioningStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
}
