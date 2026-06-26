<?php

namespace App\Modules\Tasks\Contracts;

use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface TaskAssignedRecipientResolver
{
    public function supports(Model $assignedTo): bool;

    /**
     * @return Collection<int, InternalNotificationRecipient>
     */
    public function resolve(Model $assignedTo): Collection;
}