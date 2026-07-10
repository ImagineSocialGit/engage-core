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
                        'related_type' => $task->related_type,
                        'related_id' => $task->related_id,
                        'assigned_to_type' => $task->assigned_to_type,
                        'assigned_to_id' => $task->assigned_to_id,
                        'responsible_party' => $task->responsible_party,
                        'responsible_type' => $task->responsible_type,
                        'responsible_id' => $task->responsible_id,
                        'flow_route_progress_id' => $task->flow_route_progress_id,
                        'flow_route_plan_id' => $task->flow_route_plan_id,
                        'flow_route_plan_item_id' => $task->flow_route_plan_item_id,
                        'flow_route_progress_item_id' => $task->flow_route_progress_item_id,
                        'flow_route_id' => $task->flow_route_id,
                        'flow_route_point_id' => $task->flow_route_point_id,
                        'flow_route_capability_id' => $task->flow_route_capability_id,
                        'task_template_id' => $task->task_template_id,
                        'task_template_key' => $task->task_template_key,
                        'meta' => $task->meta ?? [],
                    ],
                ],
                meta: array_replace(
                    [
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
                    $this->flowRouteMeta($task),
                ),
            ),
        ));
    }

    private function contactId(Task $task): ?int
    {
        if ($this->isContactMorph($task->related_type) && $task->related_id) {
            return (int) $task->related_id;
        }

        if ($this->isContactMorph($task->responsible_type) && $task->responsible_id) {
            return (int) $task->responsible_id;
        }

        if ($task->flow_route_progress_id) {
            $flowRouteContactId = $task->flowRouteProgress()
                ->value('contact_id');

            return is_numeric($flowRouteContactId) ? (int) $flowRouteContactId : null;
        }

        return null;
    }

    private function isContactMorph(?string $type): bool
    {
        return $type && in_array($type, array_unique([Contact::class, (new Contact())->getMorphClass()]), true);
    }

    private function flowRouteMeta(Task $task): array
    {
        $flowRoute = [
            'flow_route_progress_id' => $task->flow_route_progress_id,
            'flow_route_plan_id' => $task->flow_route_plan_id,
            'flow_route_plan_item_id' => $task->flow_route_plan_item_id,
            'flow_route_progress_item_id' => $task->flow_route_progress_item_id,
            'flow_route_id' => $task->flow_route_id,
            'flow_route_point_id' => $task->flow_route_point_id,
            'flow_route_capability_id' => $task->flow_route_capability_id,
        ];

        $presentFlowRoute = array_filter(
            $flowRoute,
            fn (mixed $value): bool => $value !== null,
        );

        return $presentFlowRoute === []
            ? $flowRoute
            : [
                ...$flowRoute,
                'flow_route' => $presentFlowRoute,
            ];
    }
}
