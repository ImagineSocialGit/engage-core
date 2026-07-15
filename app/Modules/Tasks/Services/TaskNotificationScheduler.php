<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Contracts\TaskNotificationSchedulerContract;
use App\Modules\Tasks\Data\TaskNotification;

class TaskNotificationScheduler
{
    /**
     * @param iterable<int, TaskNotificationSchedulerContract> $schedulers
     */
    public function __construct(
        private readonly iterable $schedulers,
    ) {}

    public function schedule(TaskNotification $notification): bool
    {
        foreach ($this->schedulers as $scheduler) {
            if ($scheduler->supports($notification)) {
                return $scheduler->schedule($notification);
            }
        }

        return false;
    }
}
