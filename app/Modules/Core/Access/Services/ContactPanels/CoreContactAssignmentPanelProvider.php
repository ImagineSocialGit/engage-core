<?php

namespace App\Modules\Core\Access\Services\ContactPanels;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Contracts\Contacts\ContactPanelProvider;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Facades\Auth;

final class CoreContactAssignmentPanelProvider implements ContactPanelProvider
{
    public function __construct(
        private readonly UserAccessService $access,
        private readonly AssignmentDirectory $directory,
    ) {}

    public function panels(Contact $contact): array
    {
        $contact->loadMissing([
            'assignedUser',
            'assignedTeam',
        ]);

        $actor = Auth::user();
        $canAssign = $actor instanceof User
            && $this->access->allows($actor, 'contacts.assign');

        $assignableUsers = collect();
        $assignableTeams = collect();

        if ($canAssign) {
            $assignableUsers = $this->directory->activeUsers()
                ->map(fn (User $user): array => [
                    'id' => (int) $user->getKey(),
                    'label' => trim($user->name) !== '' ? $user->name : $user->email,
                ])
                ->values();

            $assignableTeams = $this->directory->activeTeams()
                ->map(fn (Team $team): array => [
                    'id' => (int) $team->getKey(),
                    'label' => $team->name,
                ])
                ->values();
        }

        return [
            new ContactPanel(
                key: 'core.assignment',
                title: 'Ownership',
                view: 'crm.contacts.panels.assignment',
                data: [
                    'canAssign' => $canAssign,
                    'assignedUser' => $contact->assignedUser,
                    'assignedTeam' => $contact->assignedTeam,
                    'assignableUsers' => $assignableUsers,
                    'assignableTeams' => $assignableTeams,
                ],
                sort: 15,
                module: 'core',
            ),
        ];
    }
}