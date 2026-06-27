<?php

namespace App\Modules\Tasks\Events;

use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCompleted
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'task.completed';

    public function __construct(
        public readonly Task $task,
    ) {}
}