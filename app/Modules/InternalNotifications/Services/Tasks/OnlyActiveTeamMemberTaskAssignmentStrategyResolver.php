<?php

namespace App\Modules\InternalNotifications\Services\Tasks;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Contracts\TaskAssignmentStrategyResolverContract;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class OnlyActiveTeamMemberTaskAssignmentStrategyResolver implements TaskAssignmentStrategyResolverContract
{
    public const STRATEGY = 'only_active_team_member';

    public function supports(string $strategy): bool
    {
        return trim($strategy) === self::STRATEGY;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolve(string $strategy, array $context = []): ?Model
    {
        if (! $this->supports($strategy)) {
            return null;
        }

        $teamMembers = TeamMember::query()->active()->get();

        if ($teamMembers->count() !== 1) {
            throw new InvalidArgumentException(
                'create_task_only_active_team_member_not_resolved'
            );
        }

        return $teamMembers->first();
    }
}
