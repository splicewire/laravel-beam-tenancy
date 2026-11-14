<?php

namespace Splicewire\Beam\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Splicewire\Beam\Tenancy\Exceptions\SoleTenantNotIdentified;
use Splicewire\Beam\Tenancy\Localization\TenancyError;
use Splicewire\Beam\Tenancy\Localization\TenancyHttpException;
use Splicewire\Beam\Tenancy\Resolvers\SoleTenantResolver;
use Stancl\Tenancy\Middleware\IdentificationMiddleware;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * stancl identification middleware for a sole-tenant install: it initializes tenancy for the install's only eligible
 * tenant ({@see SoleTenantResolver}), and refuses with 503 when there is none or more than one.
 *
 * A sibling of stancl's domain, subdomain, path and request-data initializers, and registered in the same tenancy
 * middleware priority ({@see \Splicewire\Beam\Tenancy\BeamTenancyServiceProvider}), not a parallel mechanism. Like
 * them it runs before authentication and never reads the seat or the session.
 */
class InitializeTenancyBySoleTenant extends IdentificationMiddleware
{
    /** @var callable|null */
    public static $onFail;

    public function __construct(Tenancy $tenancy, SoleTenantResolver $resolver)
    {
        $this->tenancy = $tenancy;
        $this->resolver = $resolver;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            return $this->initializeTenancy($request, $next);
        } catch (SoleTenantNotIdentified $e) {
            // Raised as a concrete HttpException so every exception handler answers 503. A host handler that maps
            // only `HttpException` (the flagship's envelope renderer does) would otherwise answer 500 for a refusal
            // that is not a server fault. A host's own `$onFail` still sees the original first.
            $error = $e->explicitCodedError();
            if ($error instanceof TenancyError) {
                throw TenancyHttpException::for(503, $error, [], $e, $e->getHeaders());
            }

            throw new HttpException(503, __('beam-tenancy::messages.sole_tenant_unknown', ['detail' => $e->getMessage()]), $e);
        }
    }
}
