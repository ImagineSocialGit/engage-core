<?php

namespace App\Services\CRM\Tasks;

use App\Contracts\CRM\Tasks\TaskAssignedRecipientResolver;
use App\Models\Task;
use App\Services\Messaging\InternalNotificationRecipient;
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