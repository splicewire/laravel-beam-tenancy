<?php

namespace Splicewire\Beam\Tenancy\Seats;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * Seats the person who just accepted a workspace invitation ({@see WorkspaceSeats}): accepting attaches only the central
 * pivot, so without this the new member's first tenant request answers 403. Lifted from the flagship (product-journeys J6).
 * After commit, so a rolled-back acceptance seats no one.
 */
class SeatAcceptedInvitations implements ShouldHandleEventsAfterCommit
{
    public function __construct(private WorkspaceSeats $seats) {}

    public function updated(Invitation $invitation): void
    {
        if (! $invitation->wasChanged('accepted_at') || $invitation->accepted_at === null) {
            return;
        }

        $tenant = Tenant::query()->find($invitation->team_id);
        if (! $tenant instanceof Tenant) {
            return;
        }

        $member = $tenant->users()
            ->wherePivotNull('removed_at')
            ->whereRaw('lower(trim(users.email)) = ?', [mb_strtolower(trim((string) $invitation->email))])
            ->first();
        if ($member === null) {
            return;
        }

        $this->seats->seat($tenant, $member, (string) $member->getRelationValue('pivot')?->getAttribute('role'));
    }
}
