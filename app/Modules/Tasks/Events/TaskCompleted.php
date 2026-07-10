<?php

namespace App\Modules\Tasks\Events;

use App\Modules\Tasks\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCompleted
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'task.completed';

    public readonly CarbonImmutable $occurredAt;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly Task $task,
        public readonly ?string $actorType = null,
        public readonly ?int $actorId = null,
        public readonly string $source = 'tasks',
        public readonly array $meta = [],
        ?CarbonInterface $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt
            ? CarbonImmutable::instance($occurredAt)
            : CarbonImmutable::instance($task->completed_at ?? now());
    }
}
