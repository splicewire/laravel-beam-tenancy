<?php

namespace Splicewire\Beam\Tenancy\Localization;

use Illuminate\Http\Request;
use Splicewire\Beam\Localization\Contracts\TenantLocaleSource;
use Splicewire\Beam\Localization\DeliveryRecipient;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * Beam's tenant slot, contributed by beam-tenancy (localization 36; ruling 03 §2 source 4 and §3 delivery source 2;
 * owner resolution (b)): the workspace's default language, from the existing `tenants.data` JSON
 * ({@see Tenant::defaultLocale()}).
 *
 * - **A request:** the tenant this request initialized, or null on a central request.
 * - **A delivery:** the workspace the delivery CONCERNS when it names a tenant ({@see DeliveryRecipient::$workspace},
 *   e.g. the invitation's team on a tenancy host), else the bootstrapped delivery context (the queue tenancy
 *   bootstrapper's tenant), else null. It never reads the sender's tenant for a message about another workspace.
 *
 * Whether the answer is supported is the resolver's call.
 */
final class TenantDefaultLocaleSource implements TenantLocaleSource
{
    public function __construct(private TenancyContext $tenancy) {}

    public function locale(?Request $request, mixed $recipient = null): ?string
    {
        if ($request === null && $recipient instanceof DeliveryRecipient && $recipient->workspace instanceof Tenant) {
            return $recipient->workspace->defaultLocale();
        }

        $tenant = $this->tenancy->currentTenant();

        return $tenant instanceof Tenant ? $tenant->defaultLocale() : null;
    }
}
