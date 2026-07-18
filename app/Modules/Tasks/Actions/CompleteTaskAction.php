<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Listeners\EmitTaskCompletedAutomationEvent;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskContactLinkResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CompleteTaskAction
{
    public function __construct(
        private readonly TaskContactLinkResolver $contactLinks,
        private readonly EmitTaskCompletedAutomationEvent $emitTaskCompletedAutomationEvent,
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public function handle(
        Task $task,
        ?Model $actor = null,
        ?string $source = null,
        array $meta = [],
    ): Task {
        [$task, $completionEvent] = DB::transaction(function () use (
            $task,
            $actor,
            $source,
            $meta,
        ): array {
            $task = Task::query()
                ->lockForUpdate()
                ->findOrFail($task->getKey());

            $wasCompleted = $task->isCompleted();

            $task->forceFill([
                'status' => Task::STATUS_COMPLETED,
                'completed_at' => $task->completed_at ?? now(),
                'canceled_at' => null,
                'canceled_reason' => null,
            ])->save();

            $task->refresh();

            $this->touchLinkedContacts($task);

            if ($wasCompleted) {
                return [$task, null];
            }

            $completionEvent = new TaskCompleted(
                task: $task,
                actorType: $actor?->getMorphClass(),
                actorId: $actor ? (int) $actor->getKey() : null,
                source: $source ?: 'tasks',
                meta: $meta,
                occurredAt: CarbonImmutable::instance($task->completed_at),
            );

            $this->emitTaskCompletedAutomationEvent->handle($completionEvent);

            return [$task, $completionEvent];
        });

        if ($completionEvent instanceof TaskCompleted) {
            event($completionEvent);
        }

        return $task->refresh();
    }

    private function touchLinkedContacts(Task $task): void
    {
        $contactIds = $this->contactLinks->contactIds($task);

        if ($contactIds === []) {
            return;
        }

        Contact::query()
            ->whereKey($contactIds)
            ->update([
                'last_activity_at' => now(),
            ]);
    }
}