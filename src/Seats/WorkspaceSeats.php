<?php

namespace Splicewire\Beam\Tenancy\Seats;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * A workspace seat (lifted from the flagship's product-journeys J6 `App\Support\WorkspaceSeats`, launch row
 * qitem-20261021011058-9fa6ba0e).
 *
 * Two records make a seat, in different databases. The central `tenant_users` pivot says WHO holds a seat and at which
 * membership tier (beam-accounts' Role: owner · admin · member). The tenant's own `users` table holds the MIRROR
 * ({@see TenantUser}), which is what a tenant request admits and what holds the roles every tenant permission reads.
 * Accepting an invitation attached only the pivot, so an invitee's first tenant request answered 403, and a tier change
 * moved only the pivot, so the role never followed.
 *
 * {@see seat()} writes both halves: the mirror, and exactly the tenant role its tier holds (`beam.tenancy.seat_roles`,
 * default owner/admin → Admin, member → User), removing the other mapped tier roles. Roles outside the map are never
 * touched, and a tier the map does not name (`service`, the machine-identity rows) changes nothing. Idempotent.
 * Everything runs inside the tenant's own context (`$tenant->run()`), never on the central connection.
 */
class WorkspaceSeats
{
    /** @return array<string, string> membership tier => tenant role */
    public static function tierRoles(): array
    {
        return (array) config('beam.tenancy.seat_roles', ['owner' => 'Admin', 'admin' => 'Admin', 'member' => 'User']);
    }

    /**
     * Seat `$user` (the central user) in `$tenant` at `$tier`: mirror them, give the mirror exactly the tier's role and remove the other
     * mapped tier roles. Returns whether anything changed. A tier the map does not name changes nothing.
     */
    public function seat(Tenant $tenant, Model $user, string $tier): bool
    {
        return $tenant->run(fn (): bool => $this->apply($this->planInContext($user, $tier), $user));
    }

    /**
     * What {@see seat()} would change, without writing: e.g. ['mirror', '+User', '-Admin']. Empty when the seat is
     * already right or the tier is unmapped.
     *
     * @return list<string>
     */
    public function plan(Tenant $tenant, Model $user, string $tier): array
    {
        return $tenant->run(fn (): array => $this->planInContext($user, $tier));
    }

    /** @return list<string> */
    private function planInContext(Model $user, string $tier): array
    {
        $roles = self::tierRoles();
        $role = $roles[$tier] ?? null;

        if ($role === null) {
            return [];
        }

        $mirror = TenantUser::query()->find($user->getKey());
        $changes = $mirror === null ? ['mirror'] : [];

        foreach (array_unique(array_values($roles)) as $tierRole) {
            $holds = $mirror !== null && $mirror->hasRole($tierRole);
            if ($tierRole === $role && ! $holds) {
                $changes[] = '+'.$tierRole;
            } elseif ($tierRole !== $role && $holds) {
                $changes[] = '-'.$tierRole;
            }
        }

        return $changes;
    }

    /** @param  list<string>  $changes */
    private function apply(array $changes, Model $user): bool
    {
        if ($changes === []) {
            return false;
        }

        $mirror = TenantUser::query()->find($user->getKey()) ?? TenantUser::query()->create([
            'id' => $user->getKey(),
            'name' => $user->getAttribute('name'),
            'email' => $user->getAttribute('email'),
            'password' => $user->getAttribute('password'),
        ]);

        foreach ($changes as $change) {
            if (str_starts_with($change, '+')) {
                $mirror->assignRole(substr($change, 1));
            } elseif (str_starts_with($change, '-')) {
                $mirror->removeRole(substr($change, 1));
            }
        }

        return true;
    }
}
