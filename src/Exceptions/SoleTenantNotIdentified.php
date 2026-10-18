<?php

namespace Splicewire\Beam\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A sole-tenant install could not name its tenant: it has none, or more than one ({@see
 * \Splicewire\Beam\Tenancy\Resolvers\SoleTenantResolver}).
 *
 * A stancl identification failure, so `IdentificationMiddleware::$onFail` still sees it. It is ALSO an HTTP exception
 * carrying 503: the request was well-formed and the install is not in a state that can serve it. It is not a 404 (the
 * route exists) and not a 403 (no principal was judged), and it is never answered by guessing a tenant.
 */
class SoleTenantNotIdentified extends TenantCouldNotBeIdentifiedException implements HttpExceptionInterface
{
    public static function empty(): self
    {
        return new self('This sole-tenant install has no tenant yet.');
    }

    public static function ambiguous(): self
    {
        return new self('This install is configured for one tenant but has more than one; it will not guess which to serve.');
    }

    public function getStatusCode(): int
    {
        return 503;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
