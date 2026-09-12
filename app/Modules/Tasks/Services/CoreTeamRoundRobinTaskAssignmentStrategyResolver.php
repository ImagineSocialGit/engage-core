<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Core\Access\Services\TeamRoundRobinAssigneeResolver;
use App\Modules\Tasks\Contracts\TaskAssignmentStrategyResolverContract;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class CoreTeamRoundRobinTaskAssignmentStrategyResolver implements TaskAssignmentStrategyResolverContract
{
    public const PREFIX = 'team_round_robin:';

    public function __construct(
        private readonly AssignmentDirectory $directory,
        private readonly TeamRoundRobinAssigneeResolver $roundRobin,
    ) {}

    public function supports(string $strategy): bool
    {
        return preg_match('/^'.preg_quote(self::PREFIX, '/').'[1-9][0-9]*$/', trim($strategy)) === 1;
    }

    public function resolve(string $strategy, array $context = []): ?Model
    {
        $teamId = (int) substr(trim($strategy), strlen(self::PREFIX));
        $team = $this->directory->activeTeam($teamId);

        if (! $team) {
            throw new InvalidArgumentException('The round-robin Team is missing or inactive.');
        }

        return $this->roundRobin->next($team, 'tasks');
    }
}