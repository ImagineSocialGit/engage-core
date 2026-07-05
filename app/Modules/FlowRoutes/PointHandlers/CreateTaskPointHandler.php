<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\CreateTaskPointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Models\Task;

class CreateTaskPointHandler implements PointHandler
{
    public function __construct(
        private readonly CreateTaskAction $createTask,
    ) {}

    public function type(): string
    {
        return Point::TYPE_CREATE_TASK;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = CreateTaskPointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_create_task_point_definition',
                meta: [
                    'create_task_definition' => $definition->toMetaPayload(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        $assignee = $this->assignee($definition);

        if (($assignee['failed_reason'] ?? null) !== null) {
            return PointExecutionResult::failed(
                reason: $assignee['failed_reason'],
                meta: [
                    'create_task_definition' => $definition->toMetaPayload(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        $task = $this->createTask->handle([
            'related_type' => Contact::class,
            'related_id' => $context->progress->contact_id,

            'assigned_to_type' => $assignee['assigned_to_type'],
            'assigned_to_id' => $assignee['assigned_to_id'],

            'responsible_party' => $definition->responsibleParty,
            'responsible_type' => $definition->responsibleType,
            'responsible_id' => $definition->responsibleId,

            'source' => Task::SOURCE_MODULE,
            'title' => $this->renderText($definition->title, $context),
            'description' => $this->renderText($definition->description, $context),
            'due_at' => $this->dueAt($definition, $context),
            'priority' => $definition->priority,
            'meta' => [
                'created_by' => [
                    'module' => 'flow_routes',
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_id' => $context->progress->flow_route_id,
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
                'definition' => $definition->meta,
            ],
        ]);

        return PointExecutionResult::completed(
            reason: 'task_created',
            meta: [
                'task' => [
                    'id' => $task->getKey(),
                    'related_type' => $task->related_type,
                    'related_id' => $task->related_id,
                    'assigned_to_type' => $task->assigned_to_type,
                    'assigned_to_id' => $task->assigned_to_id,
                    'responsible_party' => $task->responsible_party,
                    'responsible_type' => $task->responsible_type,
                    'responsible_id' => $task->responsible_id,
                    'source' => $task->source,
                    'title' => $task->title,
                    'status' => $task->status,
                    'due_at' => $task->due_at?->toISOString(),
                ],
            ],
        );
    }

    /**
     * @return array{assigned_to_type: string|null, assigned_to_id: int|null, failed_reason?: string}
     */
    private function assignee(CreateTaskPointDefinition $definition): array
    {
        if ($definition->assignedTo !== 'only_active_team_member') {
            return [
                'assigned_to_type' => $definition->assignedToType,
                'assigned_to_id' => $definition->assignedToId,
            ];
        }

        $teamMembers = TeamMember::query()
            ->active()
            ->get();

        if ($teamMembers->count() !== 1) {
            return [
                'assigned_to_type' => null,
                'assigned_to_id' => null,
                'failed_reason' => 'create_task_only_active_team_member_not_resolved',
            ];
        }

        $teamMember = $teamMembers->first();

        return [
            'assigned_to_type' => $teamMember->getMorphClass(),
            'assigned_to_id' => $teamMember->getKey(),
        ];
    }

    private function renderText(?string $value, PointExecutionContext $context): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtr($value, [
            '{contact.id}' => (string) $context->progress->contact_id,
            '{contact_status.id}' => (string) $context->progress->contact_status_id,
            '{workflow_profile.id}' => (string) $context->progress->contact_workflow_profile_id,
            '{flow_route.id}' => (string) $context->progress->flow_route_id,
            '{flow_route_point.id}' => (string) $context->flowRoutePoint->getKey(),
            '{point.id}' => (string) $context->flowRoutePoint->point_id,
        ]);
    }

    private function dueAt(
        CreateTaskPointDefinition $definition,
        PointExecutionContext $context,
    ): mixed {
        if ($definition->dueAt === null) {
            return null;
        }

        if (! is_string($definition->dueAt)) {
            return $definition->dueAt;
        }

        return $this->renderText($definition->dueAt, $context);
    }
}
