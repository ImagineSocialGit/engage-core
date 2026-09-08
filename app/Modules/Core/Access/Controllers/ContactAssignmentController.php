<?php

namespace App\Modules\Core\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Access\Actions\AssignContactOwnershipAction;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ContactAssignmentController extends Controller
{
    public function __invoke(
        Request $request,
        Contact $contact,
        AssignContactOwnershipAction $assignOwnership,
        UserAccessService $access,
    ): RedirectResponse {
        $validated = $request->validate([
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ]);

        $assignedUser = filled($validated['assigned_user_id'] ?? null)
            ? User::query()->findOrFail((int) $validated['assigned_user_id'])
            : null;
        $assignedTeam = filled($validated['assigned_team_id'] ?? null)
            ? Team::query()->findOrFail((int) $validated['assigned_team_id'])
            : null;

        if ($assignedUser instanceof User && ! $access->isActive($assignedUser)) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'Choose an active CRM user.',
            ]);
        }

        if ($assignedTeam instanceof Team && ! $assignedTeam->is_active) {
            throw ValidationException::withMessages([
                'assigned_team_id' => 'Choose an active Team.',
            ]);
        }

        if ($assignedUser instanceof User && $assignedTeam instanceof Team) {
            $belongsToTeam = DB::table('team_user')
                ->where('user_id', $assignedUser->getKey())
                ->where('team_id', $assignedTeam->getKey())
                ->exists();

            if (! $belongsToTeam) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => 'The assigned person must belong to the selected Team.',
                ]);
            }
        }

        $assignOwnership->handle(
            contact: $contact,
            assignedUser: $assignedUser,
            assignedTeam: $assignedTeam,
            actor: $request->user(),
        );

        return back()->with('success', 'Contact ownership updated.');
    }
}