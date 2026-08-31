<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Tenancy\Support\TenancyConnections;
use Throwable;

/**
 * Reports `tenant_users` rows that are machine-shaped — either the user holds a machine identity in
 * that tenant, or the seat's `role` is outside {@see Role}.
 *
 * ## ⚠️ ADVISORY. This audit must never throw, and must never Fail.
 *
 * The estate's standing rule: throw only on what the DECLARATION'S AUTHOR could have gotten right
 * without knowing which host would load it — grammar, a missing subject, a duplicate. Anything whose
 * answer is a fact about the HOST is an advisory finding.
 *
 * *"Is there a machine-shaped row in this host's `tenant_users` table?"* is as host-dependent a
 * question as exists. The answer is somebody's production data. It is unknowable at declaration
 * time, it differs per host and per day, and — decisively — **a host in this state must still boot.**
 * These rows are the very thing this change exists to migrate; a check that refused to boot while
 * they remain would brick every host that has not yet run the migration that does not yet exist.
 *
 * This is the estate's most expensive recurring lesson in its cheapest form. A new event catalog
 * once threw at boot on a resource prefix that was registered at the flagship and absent at
 * `~/Herd/tower`, and tower could not boot at all until it was downgraded to a doctor finding. The
 * shape is identical here, so the finding is `Warn` from the start rather than after an outage.
 *
 * Everything below is therefore wrapped: a missing table, a missing column, an unreadable
 * connection and an outright driver error all degrade to a `Pass` that SAYS it measured nothing.
 * A `Pass` from this audit is never proof of cleanliness on its own — see {@see DoctorAudit}'s own
 * warning about empty populations.
 */
class MachineIdentityOnMembershipPivotAudit implements DoctorAudit
{
    public const CHECK = 'tenancy.machine-identity-on-membership-pivot';

    /**
     * ## ⚠️ `tenant_users` is CENTRAL, and this audit used to read it with no pin at all
     * Every read below goes through {@see TenancyConnections::central()} rather than the ambient default,
     * which is the defect realm-and-floor-reconciliation opened this file for. A bare
     * `DB::table('tenant_users')` did resolve correctly at this estate, and not by accident: we are
     * schema-per-tenant on ONE Postgres and {@see \Splicewire\Beam\Tenancy\PostgreSQLSchemaManager}
     * deliberately sets the tenant `search_path` to `"$tenantSchema,public"`, so a central-only table
     * falls THROUGH to `public` while a table present in both resolves to the tenant copy. Measured
     * 2026-08-30: inside an initialized tenant context with `database.default` set to `tenant`, the
     * unpinned read still returned all 42 central rows.
     *
     * What it is not is portable. On a **database-per-tenant** host there is no shared `public` to fall
     * through to, so the same line asks a database with no such table and this audit reports "no
     * membership pivot" on a host that has one — a check going silent, which is the failure mode this
     * estate pays for most often.
     *
     * It is NOT routed through {@see \Splicewire\Beam\Tenancy\Models\TenantMembership}, which holds
     * the literal `central` pin and its `@central-floor` citation. That was tried and reverted: this
     * package's harness names its only sqlite connection `testing` and defines no `central`, so the model
     * route makes the schema probe answer "no such table" and the audit degrades to a self-describing
     * pass — three of these tests flip Warn → Pass. An audit that stops detecting while still reporting
     * green is strictly worse than the portability bug it was meant to close. The resolver degrades the
     * other way: unknown split ⇒ the default connection, which is what a single-database host means.
     */
    private function connection(): ?string
    {
        return TenancyConnections::central();
    }

    /** The central query builder for a table, or the default connection's where no split is declared. */
    private function table(string $table): Builder
    {
        return DB::connection($this->connection())->table($table);
    }

    private function schema(): SchemaBuilder
    {
        return Schema::connection($this->connection());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        try {
            return [$this->measure()];
        } catch (Throwable $e) {
            // ⚠️ The catch-all is the point, not laziness. This audit reads a host's live data over
            // a host's connection; every failure mode there is a fact about the host. Reporting the
            // failure as a passing, self-describing finding is what keeps `surgeon:audit` runnable
            // on a host whose database is not even up.
            return [Finding::pass(
                self::CHECK,
                'Could not read this host\'s membership pivot, so nothing was measured: '
                    .$e->getMessage(),
            )];
        }
    }

    private function measure(): Finding
    {
        if (! $this->schema()->hasTable('tenant_users')) {
            return Finding::pass(
                self::CHECK,
                'This host has no `tenant_users` table, so there is no membership pivot to measure.',
            );
        }

        $machineSeats = $this->machineIdentitySeats();
        $foreignRoles = $this->rolesOutsideTheHumanVocabulary();

        if ($machineSeats === 0 && $foreignRoles === []) {
            return Finding::pass(
                self::CHECK,
                'Every `tenant_users` seat carries a role inside the human vocabulary ('
                    .implode('|', Role::values()).'), and no seat belongs to a user holding a machine '
                    .'identity in that same tenant.',
            );
        }

        $parts = [];

        if ($machineSeats > 0) {
            $parts[] = sprintf(
                '%d membership seat(s) belong to a user that ALSO holds a machine identity in the '
                    .'same tenant. A machine\'s presence is recorded in `tenant_machine_identities`; '
                    .'a duplicate seat on the pivot is billable, sorts into human seat lists, and is '
                    .'what the machine-identity split exists to retire.',
                $machineSeats,
            );
        }

        foreach ($foreignRoles as $role => $count) {
            $parts[] = sprintf(
                '%d seat(s) carry role `%s`, which is outside %s (%s). `Tenant::memberRole()` raises '
                    .'a ValueError on these. `service` in particular is a MACHINE axis wearing the '
                    .'membership role column — it belongs in `tenant_machine_identities`.',
                $count,
                $role,
                Role::class,
                implode('|', Role::values()),
            );
        }

        // Warn, never Fail — see the class docblock. A host in this state is a host awaiting a data
        // migration, which is a normal state to be in and not a readiness failure.
        return Finding::warn(self::CHECK, implode(' ', $parts));
    }

    /**
     * Seats whose user holds a machine identity in the same tenant. Zero when the new table is not
     * published yet, which is the ordinary state on every host until the migration runs — reported
     * as "nothing to compare against" rather than as clean.
     */
    private function machineIdentitySeats(): int
    {
        if (! $this->schema()->hasTable('tenant_machine_identities')) {
            return 0;
        }

        return $this->table('tenant_users')
            ->join('tenant_machine_identities', function ($join) {
                $join->on('tenant_users.tenant_id', '=', 'tenant_machine_identities.tenant_id')
                    ->on('tenant_users.user_id', '=', 'tenant_machine_identities.user_id');
            })
            ->whereNull('tenant_machine_identities.revoked_at')
            ->count();
    }

    /**
     * `role` values outside {@see Role}, counted per distinct value so the finding can name them —
     * `service` being the one this change is about, but the audit deliberately does not special-case
     * it. Any foreign value is the same defect.
     *
     * @return array<string, int>
     */
    private function rolesOutsideTheHumanVocabulary(): array
    {
        if (! $this->schema()->hasColumn('tenant_users', 'role')) {
            return [];
        }

        $rows = $this->table('tenant_users')
            ->select('role', DB::raw('count(*) as seats'))
            ->whereNotIn('role', Role::values())
            ->groupBy('role')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->role] = (int) $row->seats;
        }

        return $counts;
    }
}
