<?php

namespace App\Modules\FlowRoutes\Listeners;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Actions\ResumeFlowRouteProgressFromEventAction;
use App\Modules\FlowRoutes\Data\FlowRouteExternalEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class ResumeFlowRouteProgressWhenTaskCompleted
{
    public function __construct(
        private readonly ResumeFlowRouteProgressFromEventAction $resumeFlowRouteProgressFromEvent,
    ) {}

    public function handle(object $event): void
    {
        $task = $this->taskFromEvent($event);

        if (! $task) {
            return;
        }

        $this->resumeFlowRouteProgressFromEvent->handle(
            FlowRouteExternalEvent::make(
                name: 'task.completed',
                contactId: $this->contactId($task),
                subjectType: 'task',
                subjectId: $task->getKey(),
                occurredAt: $this->completedAt($task),
                payload: [
                    'task_id' => $task->getKey(),
                    'task_status' => $task->getAttribute('status'),
                    'related_type' => $task->getAttribute('related_type'),
                    'related_id' => $task->getAttribute('related_id'),
                    'assigned_to_type' => $task->getAttribute('assigned_to_type'),
                    'assigned_to_id' => $task->getAttribute('assigned_to_id'),
                ],
            ),
        );
    }

    private function taskFromEvent(object $event): ?Model
    {
        if (! property_exists($event, 'task')) {
            return null;
        }

        $task = $event->task;

        return $task instanceof Model ? $task : null;
    }

    private function contactId(Model $task): ?int
    {
        $relatedId = $task->getAttribute('related_id');

        if (! is_numeric($relatedId)) {
            return null;
        }

        if (! $this->isRelatedToContact($task)) {
            return null;
        }

        return (int) $relatedId;
    }

    private function isRelatedToContact(Model $task): bool
    {
        $relatedType = $task->getAttribute('related_type');

        if (! is_string($relatedType) || trim($relatedType) === '') {
            return false;
        }

        return in_array($relatedType, [
            Contact::class,
            (new Contact())->getMorphClass(),
        ], true);
    }

    private function completedAt(Model $task): ?CarbonInterface
    {
        $completedAt = $task->getAttribute('completed_at');

        return $completedAt instanceof CarbonInterface ? $completedAt : null;
    }
}