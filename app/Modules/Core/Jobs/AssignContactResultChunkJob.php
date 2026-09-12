<?php

namespace App\Modules\Core\Jobs;

use App\Models\User;
use App\Modules\Core\Access\Actions\AssignContactOwnershipAction;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\TeamRoundRobinAssigneeResolver;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class AssignContactResultChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<int, int> $contactIds */
    public function __construct(
        public readonly array $contactIds,
        public readonly string $mode,
        public readonly ?int $assignedUserId,
        public readonly ?int $assignedTeamId,
        public readonly bool $onlyUnassigned,
        public readonly int $actorUserId,
        public readonly string $operationId,
    ) {}

    public function handle(
        UserAccessService $access,
        ContactVisibility $visibility,
        AssignmentDirectory $directory,
        TeamRoundRobinAssigneeResolver $roundRobin,
        AssignContactOwnershipAction $assign,
    ): void {
        $actor = User::query()->find($this->actorUserId);

        if (! $actor instanceof User || ! $access->allows($actor, 'contacts.assign')) {
            return;
        }

        $user = $this->assignedUserId ? $directory->activeUser($this->assignedUserId) : null;
        $team = $this->assignedTeamId ? $directory->activeTeam($this->assignedTeamId) : null;

        if (($this->mode === 'user' && ! $user)
            || (in_array($this->mode, ['team', 'team_round_robin'], true) && ! $team)
        ) {
            return;
        }

        $query = $visibility->apply(
            Contact::query()->whereIn('contacts.id', $this->contactIds),
            $actor,
        );

        if ($this->onlyUnassigned) {
            $query->whereNull('assigned_user_id')->whereNull('assigned_team_id');
        }

        $query->reorder()->orderBy('contacts.id')->get()->each(
            function (Contact $contact) use ($user, $team, $roundRobin, $assign, $actor): void {
                if (data_get($contact->meta, 'assignment.operation_id') === $this->operationId) {
                    return;
                }

                $resolvedUser = $this->mode === 'team_round_robin' && $team instanceof Team
                    ? $roundRobin->next($team, 'contacts')
                    : $user;

                $assign->handle(
                    contact: $contact,
                    assignedUser: $resolvedUser,
                    assignedTeam: in_array($this->mode, ['team', 'team_round_robin'], true) ? $team : null,
                    actor: $actor,
                    source: 'contact_result_action',
                    context: ['operation_id' => $this->operationId],
                );
            },
        );
    }
}