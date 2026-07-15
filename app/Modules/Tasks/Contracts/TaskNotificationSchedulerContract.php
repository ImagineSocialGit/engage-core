<?php

namespace App\Modules\Tasks\Contracts;

use App\Modules\Tasks\Data\TaskNotification;

interface TaskNotificationSchedulerContract
{
    public function supports(TaskNotification $notification): bool;

    public function schedule(TaskNotification $notification): bool;
}
