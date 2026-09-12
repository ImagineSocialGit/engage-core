<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Validation\ValidationException;

final class TaskTemplateAssignmentResolver
{
    public function __construct(private readonly AssignmentDirectory $directory) {}

    /** @return array{assigned_to_type: ?string, assigned_to_id: ?int, assigned_to_strategy: ?string} */
    public function attributes(array $validated): array
    {
        $mode = $validated['assignment_mode'] ?? 'unassigned';

        if ($mode === 'unassigned') {
            return ['assigned_to_type' => null, 'assigned_to_id' => null, 'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED];
        }

        if ($mode === 'user') {
            $user = $this->directory->activeUser((int) ($validated['assigned_user_id'] ?? 0));

            if (! $user) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Choose an active person.']);
            }

            return ['assigned_to_type' => $user->getMorphClass(), 'assigned_to_id' => (int) $user->getKey(), 'assigned_to_strategy' => null];
        }

        $team = $this->directory->activeTeam((int) ($validated['assigned_team_id'] ?? 0));

        if (! $team) {
            throw ValidationException::withMessages(['assigned_team_id' => 'Choose an active Team.']);
        }

        if ($mode === 'team') {
            return ['assigned_to_type' => $team->getMorphClass(), 'assigned_to_id' => (int) $team->getKey(), 'assigned_to_strategy' => null];
        }

        if (! $team->users()->exists()) {
            throw ValidationException::withMessages(['assigned_team_id' => 'Round-robin requires a Team with at least one member.']);
        }

        return [
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'assigned_to_strategy' => CoreTeamRoundRobinTaskAssignmentStrategyResolver::PREFIX.$team->getKey(),
        ];
    }

    /** @return array{mode: string, user_id: ?int, team_id: ?int} */
    public function formState(TaskTemplate $template): array
    {
        $strategy = trim((string) $template->assigned_to_strategy);

        if (str_starts_with($strategy, CoreTeamRoundRobinTaskAssignmentStrategyResolver::PREFIX)) {
            return [
                'mode' => 'team_round_robin',
                'user_id' => null,
                'team_id' => (int) substr($strategy, strlen(CoreTeamRoundRobinTaskAssignmentStrategyResolver::PREFIX)),
            ];
        }

        if ($template->assigned_to_type && $template->assigned_to_id) {
            $assigned = $template->assignedTo;

            return [
                'mode' => $assigned instanceof \App\Models\User ? 'user' : 'team',
                'user_id' => $assigned instanceof \App\Models\User ? (int) $assigned->getKey() : null,
                'team_id' => $assigned instanceof \App\Modules\Core\Access\Models\Team ? (int) $assigned->getKey() : null,
            ];
        }

        return ['mode' => 'unassigned', 'user_id' => null, 'team_id' => null];
    }
}