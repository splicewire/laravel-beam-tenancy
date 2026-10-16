<?php

namespace Splicewire\Beam\Tenancy\Loopback;

use Rushing\SaloonKernel\Contracts\KernelContextBridge;

/**
 * The tenancy binding for the `rushing/laravel-saloon-kernel` transport: it captures the caller's
 * current tenant before an in-process kernel dispatch and restores it after, so the outer
 * orchestration stays on the right tenant schema once the nested request has re-resolved tenancy
 * from its own subdomain Host.
 *
 * This is the one place the tenancy-specific save/restore lives — the transport package stays free
 * of any stancl/tenancy dependency (it only knows *when* to capture/restore, via
 * {@see KernelContextBridge}, never *what*). Lifted from the flagship's `app/Splicewire/Loopback`
 * on 2026-10-16 (this package's ADR-0004): the owner ruled the mechanism lives with the tenancy
 * package, behind a soft edge — {@see \Splicewire\Beam\Tenancy\BeamTenancyServiceProvider} binds it
 * only when the contract is loaded, and the contract arrives via require-dev/suggest, never a hard
 * require. Both known consumers — the flagship's dogfood loopback (app ADR-0093) and tower's
 * federation resolve (app ADR-0126, `KernelResolveHandler`) — pick it up through the container.
 *
 * `restore()` runs in the transport's `finally`, so it is a total inverse of `capture()`: a captured
 * tenant is re-initialized; a captured `null` (the outer context had no tenant — e.g. a central
 * call) ends any tenancy the nested request left initialized.
 */
class TenancyContextBridge implements KernelContextBridge
{
    public function capture(): mixed
    {
        return tenancy()->initialized ? tenant() : null;
    }

    public function restore(mixed $captured): void
    {
        if ($captured !== null) {
            tenancy()->initialize($captured);
        } elseif (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
