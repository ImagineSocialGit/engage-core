<?php

namespace App\Modules\Tasks\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface TaskAssigneeOptionProviderContract
{
    /**
     * @return Collection<int, \App\Modules\Tasks\Data\TaskAssigneeOption>
     */
    public function options(?Model $actor = null): Collection;
}
