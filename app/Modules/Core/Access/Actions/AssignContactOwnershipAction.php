<?php

namespace App\Modules\Core\Access\Actions;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Models\Contact;

final class AssignContactOwnershipAction
{
    public function handle(
        Contact $contact,
        ?User $assignedUser,
        ?Team $assignedTeam,
        ?User $actor = null,
    ): Contact {
        $meta = is_array($contact->meta) ? $contact->meta : [];
        $meta['assignment'] = [
            'assigned_user_id' => $assignedUser?->getKey(),
            'assigned_team_id' => $assignedTeam?->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'assigned_at' => now()->toIso8601String(),
        ];

        $contact->forceFill([
            'assigned_user_id' => $assignedUser?->getKey(),
            'assigned_team_id' => $assignedTeam?->getKey(),
            'meta' => $meta,
        ])->save();

        return $contact->refresh();
    }
}