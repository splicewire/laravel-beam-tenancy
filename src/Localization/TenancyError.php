<?php

namespace Splicewire\Beam\Tenancy\Localization;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;
use Splicewire\Beam\Tenancy\TenantSlug;

enum TenancyError: string implements CodedError
{
    use IsCodedError;

    #[Message('Cannot remove the last owner. Transfer ownership first.')]
    case LastOwnerTransferRequired = 'tenancy.last_owner_transfer_required';

    #[Message('Cannot remove the last owner.')]
    case LastOwner = 'tenancy.last_owner';

    #[Message('This sole-tenant install has no tenant yet.')]
    case SoleTenantEmpty = 'tenancy.sole_tenant_empty';

    #[Message('This install is configured for one tenant but has more than one; it will not guess which to serve.')]
    case SoleTenantAmbiguous = 'tenancy.sole_tenant_ambiguous';

    #[Message(TenantSlug::RULE)]
    case InvalidSlug = 'tenancy_invalid_slug';
}
