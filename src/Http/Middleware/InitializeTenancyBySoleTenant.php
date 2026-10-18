<?php

namespace Splicewire\Beam\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Splicewire\Beam\Tenancy\Resolvers\SoleTenantResolver;
use Stancl\Tenancy\Middleware\IdentificationMiddleware;
use Stancl\Tenancy\Tenancy;

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
        return $this->initializeTenancy($request, $next);
    }
}
