<?php

namespace App\Modules\Tasks\Services;

use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Tasks\Contracts\TaskAssignedRecipientResolver;
use App\Modules\Tasks\Models\Task;
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
        if (! $this->internalNotificationsEnabled()) {
            return collect();
        }

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

    private function internalNotificationsEnabled(): bool
    {
        return ! function_exists('module_enabled')
            || module_enabled('internal_notifications');
    }
}