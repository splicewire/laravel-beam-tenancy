<?php

namespace Splicewire\Beam\Tenancy\Exceptions;

use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Tenancy\Localization\TenancyError;
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
    private ?CodedError $codedError = null;

    public function explicitCodedError(): ?CodedError
    {
        return $this->codedError;
    }

    public static function empty(): self
    {
        $exception = new self(TenancyError::SoleTenantEmpty->defaultMessage());
        $exception->codedError = TenancyError::SoleTenantEmpty;

        return $exception;
    }

    public static function ambiguous(): self
    {
        $exception = new self(TenancyError::SoleTenantAmbiguous->defaultMessage());
        $exception->codedError = TenancyError::SoleTenantAmbiguous;

        return $exception;
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
