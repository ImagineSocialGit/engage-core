<?php

namespace App\Modules\Tasks\Contracts;

use App\Modules\Tasks\Data\TaskRecipient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface TaskAssignedRecipientResolver
{
    public function supports(Model $assignedTo): bool;

    /**
     * @return Collection<int, TaskRecipient>
     */
    public function resolve(Model $assignedTo): Collection;
}
