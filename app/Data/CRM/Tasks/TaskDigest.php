<?php

namespace App\Data\CRM\Tasks;

use App\Models\Task;
use App\Services\Messaging\InternalNotificationRecipient;
use Illuminate\Support\Collection;

class TaskDigest
{
    /**
     * @param Collection<int, Task> $tasks
     */
    public function __construct(
        public readonly InternalNotificationRecipient $recipient,
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