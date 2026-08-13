<?php

namespace Splicewire\Beam\Tenancy\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Splicewire\Beam\Accounts\Data\RoleOptionsData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Http\Controller;
use Splicewire\Beam\Tenancy\Data\TenantMemberData;

class TenantMemberController extends Controller
{
    /**
     * The role vocabulary the team UI renders its selects from — derived from the
     * beam-accounts {@see Role} enum, so there is no hand-authored TS list to drift
     * (FC-11/FC-12). `assignable` = every role a member can be set to (ownership
     * transfer included); `invitable` = the invite-context subset (owner excluded).
     * Served in the standard `{data: …}` envelope as a typed {@see RoleOptionsData}.
     */
    public function roles()
    {
        return response()->json(['data' => RoleOptionsData::current()]);
    }

    public function index(Request $request)
    {
        $tenant = tenant();

        $members = $tenant->users()->wherePivotNull('removed_at')->get()
            ->map(fn ($user) => $this->presentMember($user))
            ->values()
            ->all();

        return response()->json(['data' => $members]);
    }

    /**
     * One member row as {@see TenantMemberData} — the user projected with their pivot role and
     * accepted-at timestamp, the shape every verb here emits in its `data` slot.
     */
    protected function presentMember(object $user): TenantMemberData
    {
        $joinedAt = $user->pivot->accepted_at;

        return new TenantMemberData(
            id: (string) $user->id,
            email: $user->email,
            role: $user->pivot->role,
            name: $user->name,
            joined_at: $joinedAt instanceof \DateTimeInterface ? $joinedAt->toIso8601String() : $joinedAt,
        );
    }

    public function updateRole(Request $request, string $id)
    {
        $request->validate(['role' => ['required', Rule::in(Role::values())]]);

        $tenant = tenant();
        $currentUser = auth()->user();

        // Owner-gated via the beam-accounts `manageMembers` ability (the single seam
        // that owns membership authorization), not a hand-rolled role check.
        Gate::authorize('manageMembers', $tenant);

        // Can't change your own role if you're the last owner
        if ($id === $currentUser->id && $request->role !== Role::Owner->value) {
            $ownerCount = $tenant->users()->wherePivot('role', Role::Owner->value)->count();
            if ($ownerCount <= 1) {
                abort(422, 'Cannot remove the last owner. Transfer ownership first.');
            }
        }

        $tenant->users()->updateExistingPivot($id, ['role' => $request->role]);

        // Re-read so the data slot carries the member's post-update state.
        $member = $tenant->users()->wherePivotNull('removed_at')->find($id);
        abort_unless($member !== null, 404);

        return response()->json([
            'message' => 'Role updated.',
            'data' => $this->presentMember($member),
        ]);
    }

    public function remove(Request $request, string $id)
    {
        $tenant = tenant();
        $currentUser = auth()->user();

        // Owner-gated via the beam-accounts `manageMembers` ability (see updateRole).
        Gate::authorize('manageMembers', $tenant);

        // Can't remove yourself if last owner
        if ($id === $currentUser->id) {
            $ownerCount = $tenant->users()->wherePivot('role', Role::Owner->value)->count();
            if ($ownerCount <= 1) {
                abort(422, 'Cannot remove the last owner.');
            }
        }

        // Snapshot the seat before stamping removed_at so the data slot carries the removed
        // member's final state (the destroy-returns-the-resource envelope rule).
        $member = $tenant->users()->wherePivotNull('removed_at')->find($id);
        abort_unless($member !== null, 404);
        $snapshot = $this->presentMember($member);

        $tenant->users()->updateExistingPivot($id, ['removed_at' => now()]);

        return response()->json([
            'message' => 'Member removed.',
            'data' => $snapshot,
        ]);
    }
}
