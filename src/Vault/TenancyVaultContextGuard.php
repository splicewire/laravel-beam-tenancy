<?php

namespace Splicewire\Beam\Tenancy\Vault;

use Rushing\AuthVault\Contracts\VaultContextGuard;
use Rushing\AuthVault\Exceptions\VaultContextException;

/**
 * This package's default binding of the vault's defense-in-depth partition guard (sourced-particles
 * ticket 10, V1; lifted from the flagship's `app/Vault` on 2026-10-16, host-binding-seam ticket 02 —
 * the search_path analysis below is beam-tenancy knowledge and was recorded in a host file). Per app
 * ADR-0084 there is **no central vault** — every `vault_secrets` row lives in its owner's tenant
 * schema, and isolation is enforced by that placement. This guard refuses any vault access unless
 * tenancy is initialized.
 *
 * An earlier version of this docblock justified it by a read that would "fall through the Postgres
 * `search_path` to the shared `public` schema" and resolve a foreign secret. Measured 2026-08-31 at
 * the flagship: no reachable state does that.
 *
 *   - `vault_secrets` is a tenant-only migration (at the flagship:
 *     `database/migrations/tenant/2026_08_27_190108_create_vault_secrets_table.php`).
 *     17 of 17 tenant schemas carried the table; `public` carried none.
 *   - With tenancy uninitialized the connection's `search_path` is plain `public`, so the read
 *     resolves nothing — it raises `SQLSTATE[42P01]: relation "vault_secrets" does not exist`.
 *     A central frame cannot silently reach a tenant's rows.
 *   - `tenancy()->end()` restores `search_path` to `public`, so there is no stale-frame read after
 *     a tenant context closes either.
 *
 * The fall-through is real, but it points the other way.
 * {@see \Splicewire\Beam\Tenancy\PostgreSQLSchemaManager} deliberately sets `search_path` to
 * `"<tenant_schema>,public"` — not the schema alone, which is what stancl's stock manager would do —
 * so that public-schema extensions (vector, fuzzystrmatch) stay reachable from a tenant frame.
 * Resolution follows `search_path` order, so a tenant's own `vault_secrets` always wins; the
 * fall-through only fires outward, tenant → public, and today lands on nothing.
 *
 * So this is a fail-closed precondition, not a plug in a live leak. It earns its place on two
 * counts: it turns an opaque 42P01 into a named failure that says which conduit and which operation,
 * and — because that `,public` suffix is a deliberate choice of THIS package's schema manager — it
 * is the control that keeps the outward fall-through from becoming a genuine cross-tenant read the
 * day anything creates a `vault_secrets` table in `public` (publishing the vault package's own
 * un-namespaced migration stub centrally would do exactly that).
 *
 * It does NOT add a tenant_id column (ADR-0084 rejects that): the schema still IS the partition;
 * this only asserts a partition is actually entered. Bound `bindIf` behind an `interface_exists`
 * guard — auth-vault is suggest-tier, never a hard require — so a host that binds its own guard
 * keeps it, and a host without the vault pays nothing.
 */
class TenancyVaultContextGuard implements VaultContextGuard
{
    public function assert(string $conduitId, string $operation): void
    {
        if (! tenancy()->initialized) {
            throw VaultContextException::noContext($conduitId, $operation);
        }
    }
}
