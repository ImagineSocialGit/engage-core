<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Contracts\TaskAssignedRecipientResolver;
use App\Modules\Tasks\Models\Task;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use Illuminate\Support\Collection;

class TaskAssignedRecipientsResolver
{
    /**
     * @param iterable<int, TaskAssignedRecipientResolver> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers,
    ) {}

    /**
     * @return Collection<int, InternalNotificationRecipient>
     */
    public function resolve(Task $task): Collection
    {
        $task->loadMissing('assignedTo');

        $assignedTo = $task->assignedTo;

        if (! $assignedTo) {
            return collect();
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($assignedTo)) {
                return $resolver->resolve($assignedTo);
            }
        }

        return collect();
    }
}