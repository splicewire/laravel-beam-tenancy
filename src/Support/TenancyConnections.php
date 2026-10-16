<?php

namespace Splicewire\Beam\Tenancy\Support;

use Illuminate\Support\Facades\Config;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * Which connection a **central** (non-tenant) table is reachable on at THIS host.
 *
 * ## Why this exists rather than a literal `'central'`
 * An unpinned read of a central table from inside a tenant frame resolves correctly at THIS estate, and
 * it is worth being exact about why, because the obvious story is wrong. stancl's own
 * `PostgreSQLSchemaManager::makeConnectionConfig()` sets `search_path` to the tenant schema alone — but
 * this package **overrides it**: {@see \Splicewire\Beam\Tenancy\PostgreSQLSchemaManager} sets
 * `"$databaseName,public"`, and `HybridPostgresTenantDatabaseManager` (what
 * `~/Herd/splicewire-app/config/tenancy.php` binds) is what reaches it. So the fall-through to `public`
 * is **load-bearing config, not luck**. Probed 2026-08-31 in a real tenant frame: `search_path` reads
 * `tenant_system, public`, and a central-only table is reachable.
 *
 * The direction matters and is the useful fact: search_path order means a table present in BOTH schemas
 * resolves to the TENANT copy, while a central-only table falls through to `public`. `tenant_users`
 * exists in `public` only — measured 2026-08-30 as exactly one schema, against `beam_ux_entries`'s
 * eighteen — so it falls through, every time, by design.
 *
 * **What that does not buy is portability.** The fall-through is a property of schema-per-tenant on ONE
 * Postgres. On a database-per-tenant host there is no shared `public` to fall through to, and an unpinned
 * read asks a database that has no such table. That hazard is unchanged and is why this seam exists.
 *
 * ## Why not just write `'central'`
 * Because a package that hardcodes a connection NAME is asserting a fact about the host's
 * `config/database.php`, and that assertion is false in this package's own test harness — which names
 * its single sqlite connection `testing` and defines no `central` at all. A doctor audit pinned to a
 * literal `central` there resolves nothing, its schema probe answers "no such table", and it degrades to
 * a self-describing pass. **That is the worst outcome available**: an audit that stops finding things
 * while still reporting green. A prior attempt to move
 * {@see \Splicewire\Beam\Tenancy\Doctor\MachineIdentityOnMembershipPivotAudit} onto
 * {@see \Splicewire\Beam\Tenancy\Models\TenantMembership} (which pins the literal) did exactly that —
 * three of its tests flipped Warn → Pass — and was reverted.
 *
 * ## The seam
 * `tenancy.database.central_connection` is stancl's own declaration of which connection is central, and
 * every multi-tenant host in the estate sets it (`splicewire-app` and `standwell` to `'central'`, `tower`
 * to `env('DB_CONNECTION', 'central')`). Where it is set AND names a defined connection, that is the
 * answer. Where it is absent or dangling, the host is not running a central/tenant split for this
 * purpose and the default connection IS central — which is both correct for a single-database host and
 * exactly what makes the check keep working in a harness that models one.
 *
 * Same shape and same fallback order as `Splicewire\Satellite\Support\SatelliteTenancy::centralConnection()`.
 * The two were duplicated for want of a home below them both; beam core's {@see TenancyContext} port
 * (ADR-0239, host-binding-seam ticket 01) is that home, and this helper now delegates to it —
 * kept as the package-local static spelling because an audit sweeping a table it does not own wants
 * a one-line call, not a container dance in a docblock example.
 *
 * ⚠️ This is a **connection resolver, not a pin justification.** A model that holds a central table still
 * declares `protected $connection = 'central'` with its `@central-floor` citation, because
 * `Splicewire\Beam\Surgeon\CentralPinJustificationAudit` reads declarations and a resolver call is not
 * one. This class is for readers that have no model to pin — an audit sweeping a table it does not own.
 */
class TenancyConnections
{
    public static function central(): ?string
    {
        // `null`, not the default's NAME — the semantics live on the port now (ADR-0239): handing
        // back `config('database.default')` would look identical and behave differently the moment
        // tenancy has swapped the default out from under the caller mid-request.
        return app(TenancyContext::class)->centralConnection();
    }

    /**
     * Remove a transient connection's config outright and purge its PDO — the repository's
     * `offsetUnset` leaves a null entry behind, which `array_key_exists` and the rushing audits'
     * `ConnectionConfig` both still see. Used by the pool migrator and the doctor probe.
     */
    public static function forget(string $name): void
    {
        \Illuminate\Support\Facades\DB::purge($name);
        $connections = (array) Config::get('database.connections', []);
        unset($connections[$name]);
        Config::set('database.connections', $connections);
    }

    /**
     * Whether the host actually runs a central/tenant split — i.e. it names a central connection AND that
     * connection is not merely the default. Lets a caller say what it measured rather than assuming.
     */
    public static function isSplit(): bool
    {
        $central = self::central();

        return $central !== null && $central !== Config::get('database.default');
    }
}
