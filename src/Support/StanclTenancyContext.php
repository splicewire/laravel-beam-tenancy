<?php

namespace Splicewire\Beam\Tenancy\Support;

use Illuminate\Support\Facades\Config;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * The stancl-backed adapter for beam core's {@see TenancyContext} port (ADR-0239; this package's
 * ADR-0003 companion note): the live half — "is a tenant initialized NOW, which tenant" — that
 * core's {@see \Splicewire\Beam\Realm\CentralTenancyContext} deliberately answers false/null.
 *
 * Bound with a plain `bind()` over core's `bindIf` (the supersession lane, host-binding-seam
 * doctrine): installing the tenancy package IS the posture declaration, and the bindIf/bind pair is
 * order-independent — whichever registers first, this adapter ends up bound. A host overrides by
 * binding later still.
 *
 * `centralConnection()` keeps {@see TenancyConnections}' fallback order and null semantics (that
 * helper now delegates here through the port). `isActive()` asks stancl's live singleton — never
 * `function_exists('tenant')`: inside this package the helper is a hard dependency, and the 20
 * hand-copied probes this port retires were exactly the convention of asking "is the helper loaded"
 * when the question was "is a tenant initialized".
 */
final class StanclTenancyContext implements TenancyContext
{
    public function isMultiTenant(): bool
    {
        return $this->centralConnection() !== null;
    }

    public function isActive(): bool
    {
        return (bool) tenancy()->initialized;
    }

    public function currentTenant(): ?object
    {
        return $this->isActive() ? tenant() : null;
    }

    public function currentTenantId(): int|string|null
    {
        $tenant = $this->currentTenant();

        return $tenant?->getTenantKey();
    }

    public function centralConnection(): ?string
    {
        $configured = Config::get('tenancy.database.central_connection');

        if (is_string($configured) && $configured !== '' && Config::get("database.connections.{$configured}") !== null) {
            return $configured;
        }

        return null;
    }
}
