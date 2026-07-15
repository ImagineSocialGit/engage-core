<?php

namespace App\Modules\Tasks\Data;

use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Collection;

class TaskDigest
{
    /**
     * @param Collection<int, Task> $tasks
     */
    public function __construct(
        public readonly TaskRecipient $recipient,
        public readonly Collection $tasks,
        public readonly string $frequency,
    ) {}

    public function taskCount(): int
    {
        return $this->tasks->count();
    }

    public function hasTasks(): bool
    {
        return $this->tasks->isNotEmpty();
    }
}
