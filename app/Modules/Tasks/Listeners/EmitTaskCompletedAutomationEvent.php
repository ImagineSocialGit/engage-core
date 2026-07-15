<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Services\TaskContactLinkResolver;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;

class EmitTaskCompletedAutomationEvent
{
    public function __construct(
        private readonly TaskContactLinkResolver $contactLinks,
    ) {}

    public function handle(TaskCompleted $event): void
    {
        $task = $event->task->fresh(['links']);

        if (! $task) {
            return;
        }

        event(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: TaskCompleted::NAME,
                subject: $task,
                contactId: $this->contactId($task),
                occurredAt: $event->occurredAt,
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
                        'assigned_to_type' => $task->assigned_to_type,
                        'assigned_to_id' => $task->assigned_to_id,
                        'responsible_party' => $task->responsible_party,
                        'responsible_type' => $task->responsible_type,
                        'responsible_id' => $task->responsible_id,
                        'task_template_id' => $task->task_template_id,
                        'task_template_key' => $task->task_template_key,
                        'links' => $this->compactLinks($task),
                        'meta' => $task->meta ?? [],
                    ],
                ],
                meta: [
                    'source_module' => 'tasks',
                    'task_id' => $task->id,
                    'completion' => [
                        'source' => $event->source,
                        'actor_type' => $event->actorType,
                        'actor_id' => $event->actorId,
                        'occurred_at' => $event->occurredAt->toISOString(),
                        'meta' => $event->meta,
                    ],
                ],
            ),
        ));
    }

    private function contactId(Task $task): ?int
    {
        $linkedContact = $this->contactLinks->resolve($task);

        if ($linkedContact) {
            return (int) $linkedContact->getKey();
        }

        if ($this->isContactMorph($task->responsible_type)
            && $task->responsible_id
        ) {
            return (int) $task->responsible_id;
        }

        return null;
    }

    private function isContactMorph(?string $type): bool
    {
        return $type
            && in_array($type, array_unique([
                Contact::class,
                (new Contact())->getMorphClass(),
            ]), true);
    }

    /**
     * @return array<int, array{
     *     role: string,
     *     linkable_type: string,
     *     linkable_id: int
     * }>
     */
    private function compactLinks(Task $task): array
    {
        return $task->links
            ->sortBy(fn (TaskLink $link): string => implode(':', [
                $link->role,
                $link->linkable_type,
                (string) $link->linkable_id,
            ]))
            ->values()
            ->map(fn (TaskLink $link): array => [
                'role' => $link->role,
                'linkable_type' => $link->linkable_type,
                'linkable_id' => (int) $link->linkable_id,
            ])
            ->all();
    }
}
