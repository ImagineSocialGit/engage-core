<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;

class EmitTaskCompletedAutomationEvent
{
    public function handle(TaskCompleted $event): void
    {
        $task = $event->task->fresh();

        if (! $task) {
            return;
        }

        event(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: TaskCompleted::NAME,
                subject: $task,
                contactId: $this->contactId($task),
                occurredAt: $task->completed_at ?? now(),
                payload: [
                    'task' => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'description' => $task->description,
                        'status' => $task->status,
                        'source' => $task->source,
                        'priority' => $task->priority,
                        'due_at' => $task->due_at?->toISOString(),
                        'completed_at' => $task->completed_at?->toISOString(),
                        'related_type' => $task->related_type,
                        'related_id' => $task->related_id,
                        'assigned_to_type' => $task->assigned_to_type,
                        'assigned_to_id' => $task->assigned_to_id,
                        'meta' => $task->meta ?? [],
                    ],
                ],
                meta: [
                    'source_module' => 'tasks',
                    'task_id' => $task->id,
                ],
            ),
        ));
    }

    private function contactId(Task $task): ?int
    {
        if (! $task->related_type || ! $task->related_id) {
            return null;
        }

        $contactMorphClass = (new Contact())->getMorphClass();

        if (! in_array($task->related_type, [Contact::class, $contactMorphClass], true)) {
            return null;
        }

        return (int) $task->related_id;
    }
}