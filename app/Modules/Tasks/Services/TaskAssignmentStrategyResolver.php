<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Contracts\TaskAssignmentStrategyResolverContract;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TaskAssignmentStrategyResolver
{
    /**
     * @param iterable<int, TaskAssignmentStrategyResolverContract> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function resolve(?string $strategy, array $context = []): ?Model
    {
        $strategy = is_string($strategy) ? trim($strategy) : null;

        if ($strategy === null
            || $strategy === ''
            || $strategy === TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED
        ) {
            return null;
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($strategy)) {
                return $resolver->resolve($strategy, $context);
            }
        }

        throw new InvalidArgumentException(
            "Task assignment strategy [{$strategy}] is not available."
        );
    }
}
