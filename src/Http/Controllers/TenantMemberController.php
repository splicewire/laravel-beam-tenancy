<?php

namespace Splicewire\Beam\Tenancy\Http\Controllers;

use Splicewire\Beam\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Splicewire\Beam\Accounts\Enums\Role;

class TenantMemberController extends Controller
{
    /**
     * The role vocabulary the team UI renders its selects from — derived from the
     * beam-accounts {@see Role} enum, so there is no hand-authored TS list to drift
     * (FC-11/FC-12). `assignable` = every role a member can be set to (ownership
     * transfer included); `invitable` = the invite-context subset (owner excluded).
     */
    public function roles()
    {
        return response()->json([
            'assignable' => Role::options(Role::assignable()),
            'invitable' => Role::options(Role::invitable()),
        ]);
    }

    public function index(Request $request)
    {
        $tenant = tenant();

        return $tenant->users()->wherePivotNull('removed_at')->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'joined_at' => $user->pivot->accepted_at,
            ];
        });
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

        return response()->json(['message' => 'Role updated.']);
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

        $tenant->users()->updateExistingPivot($id, ['removed_at' => now()]);

        return response()->json(['message' => 'Member removed.']);
    }
}
