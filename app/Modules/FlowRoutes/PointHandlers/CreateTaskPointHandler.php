<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\CreateTaskPointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

class CreateTaskPointHandler implements PointHandler
{
    public function __construct(
        private readonly CreateTaskAction $createTask,
        private readonly CreateTaskFromTemplateAction $createTaskFromTemplate,
    ) {}

    public function type(): string
    {
        return FlowRoutePointType::CreateTask->value;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = CreateTaskPointDefinition::from($context->definition, $context->settings);

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_create_task_point_definition',
                meta: [
                    'create_task_definition' => $definition->toMetaPayload(),
                    'flow_routes' => $context->flowRouteProvenance(),
                ],
            );
        }

        $data = $this->taskData($definition, $context);

        try {
            $task = $definition->taskTemplateKey !== null
                ? $this->createTaskFromTemplate->handle($definition->taskTemplateKey, $data)
                : $this->createTask->handle($data);
        } catch (ModelNotFoundException) {
            return PointExecutionResult::failed('task_template_not_found', [
                'create_task_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return PointExecutionResult::failed($exception->getMessage(), [
                'create_task_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        } catch (Throwable $exception) {
            return PointExecutionResult::failed('create_task_failed', [
                'create_task_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }

        if ($context->progressItem) {
            $context->progressItem->forceFill([
                'created_subject_type' => $task->getMorphClass(),
                'created_subject_id' => $task->getKey(),
                'correlation_key' => 'task.id',
                'correlation_type' => 'task',
                'correlation' => [
                    'task_id' => $task->getKey(),
                    'task_template_id' => $task->task_template_id,
                    'task_template_key' => $task->task_template_key,
                ],
            ])->save();
        }

        return PointExecutionResult::completed('task_created', [
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
                'task_template_id' => $task->task_template_id,
                'task_template_key' => $task->task_template_key,
            ],
            'flow_routes' => $context->flowRouteProvenance(),
        ]);
    }

    /** @return array<string, mixed> */
    private function taskData(CreateTaskPointDefinition $definition, PointExecutionContext $context): array
    {
        $relatedType = $context->progress->subject_type ?: Contact::class;
        $relatedId = $context->progress->subject_id ?: $context->progress->contact_id;
        $provenance = $context->flowRouteProvenance();

        return array_filter([
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'assigned_to_type' => $definition->assignedToType,
            'assigned_to_id' => $definition->assignedToId,
            'assigned_to_strategy' => $definition->assignedToStrategy ?? $definition->assignedTo,
            'responsible_party' => $definition->responsibleParty,
            'responsible_type' => $definition->responsibleType,
            'responsible_id' => $definition->responsibleId,
            'source' => Task::SOURCE_MODULE,
            'title' => $this->renderText($definition->title, $context),
            'description' => $this->renderText($definition->description, $context),
            'due_at' => $this->dueAt($definition, $context),
            'due_offset_minutes' => $definition->dueOffsetMinutes,
            'priority' => $definition->priority,
            ...$provenance,
            'meta' => [
                'flow_routes' => $provenance,
                'definition' => $definition->meta,
            ],
        ], fn (mixed $value): bool => $value !== null);
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
            '{flow_route_progress.id}' => (string) $context->progress->getKey(),
            '{flow_route_plan.id}' => (string) $context->plan?->getKey(),
            '{flow_route_plan_item.id}' => (string) $context->planItem?->getKey(),
            '{flow_route_progress_item.id}' => (string) $context->progressItem?->getKey(),
            '{flow_route.id}' => (string) $context->progress->flow_route_id,
            '{flow_route_point.id}' => (string) $context->flowRoutePoint->getKey(),
            '{subject.type}' => (string) $context->progress->subject_type,
            '{subject.id}' => (string) $context->progress->subject_id,
        ]);
    }

    private function dueAt(CreateTaskPointDefinition $definition, PointExecutionContext $context): mixed
    {
        if ($definition->dueAt === null) {
            return null;
        }

        return is_string($definition->dueAt) ? $this->renderText($definition->dueAt, $context) : $definition->dueAt;
    }
}
