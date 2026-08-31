<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Accounts\Enums\Role;
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
        if (! Schema::hasTable('tenant_users')) {
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
        if (! Schema::hasTable('tenant_machine_identities')) {
            return 0;
        }

        return DB::table('tenant_users')
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
        if (! Schema::hasColumn('tenant_users', 'role')) {
            return [];
        }

        $rows = DB::table('tenant_users')
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
