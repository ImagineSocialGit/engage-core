<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\CreateTaskPointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

class CreateTaskPointHandler implements PointHandler
{
    public function __construct(
        private readonly CreateTaskFromTemplateAction $createTaskFromTemplate,
    ) {}

    public function type(): string
    {
        return FlowRoutePointType::CreateTask->value;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = CreateTaskPointDefinition::from(
            $context->definition,
            $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason
                    ?? 'invalid_create_task_point_definition',
                meta: [
                    'create_task_definition' => $definition->toMetaPayload(),
                    'flow_routes' => $context->flowRouteProvenance(),
                ],
            );
        }

        if ($definition->taskTemplateKey === null) {
            return PointExecutionResult::failed(
                reason: 'create_task_requires_task_template',
                meta: [
                    'create_task_definition' => $definition->toMetaPayload(),
                    'flow_routes' => $context->flowRouteProvenance(),
                ],
            );
        }

        try {
            $task = $this->createTaskFromTemplate->handle(
                $definition->taskTemplateKey,
                $this->taskData($definition, $context),
            );
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

        $task->loadMissing('links');

        return PointExecutionResult::completed('task_created', [
            'task' => [
                'id' => $task->getKey(),
                'links' => $task->links
                    ->map(fn (TaskLink $link): array => [
                        'role' => $link->role,
                        'linkable_type' => $link->linkable_type,
                        'linkable_id' => $link->linkable_id,
                    ])
                    ->values()
                    ->all(),
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

    /**
     * @return array<string, mixed>
     */
    private function taskData(
        CreateTaskPointDefinition $definition,
        PointExecutionContext $context,
    ): array {
        [$contact, $subject] = $this->taskContext($context);

        return array_filter([
            'links' => $this->taskLinks($contact, $subject),
            'link_context' => [
                TaskTemplate::LINK_SOURCE_CURRENT_CONTACT => $contact,
                TaskTemplate::LINK_SOURCE_CURRENT_SUBJECT => $subject,
            ],
            'assigned_to_type' => $definition->assignedToType,
            'assigned_to_id' => $definition->assignedToId,
            'assigned_to_strategy' => $definition->assignedToStrategy
                ?? $definition->assignedTo,
            'responsible_party' => $definition->responsibleParty,
            'responsible_type' => $definition->responsibleType,
            'responsible_id' => $definition->responsibleId,
            'source' => Task::SOURCE_MODULE,
            'title' => $this->renderText($definition->title, $context),
            'description' => $this->renderText(
                $definition->description,
                $context,
            ),
            'due_at' => $this->dueAt($definition, $context),
            'due_offset_minutes' => $definition->dueOffsetMinutes,
            'priority' => $definition->priority,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{0: Contact, 1: Model}
     */
    private function taskContext(PointExecutionContext $context): array
    {
        $progress = $context->progress->loadMissing(['contact', 'subject']);
        $contact = $progress->contact;
        $subject = $progress->subject;

        return [
            $contact,
            $subject instanceof Model ? $subject : $contact,
        ];
    }

    /**
     * @return array<int, array{linkable: Model, role: string}>
     */
    private function taskLinks(Contact $contact, Model $subject): array
    {
        if ($subject->is($contact)) {
            return [[
                'linkable' => $contact,
                'role' => TaskLink::ROLE_SUBJECT,
            ]];
        }

        return [
            [
                'linkable' => $subject,
                'role' => TaskLink::ROLE_SUBJECT,
            ],
            [
                'linkable' => $contact,
                'role' => TaskLink::ROLE_CONTEXT,
            ],
        ];
    }

    private function renderText(
        ?string $value,
        PointExecutionContext $context,
    ): ?string {
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

    private function dueAt(
        CreateTaskPointDefinition $definition,
        PointExecutionContext $context,
    ): mixed {
        if ($definition->dueAt === null) {
            return null;
        }

        return is_string($definition->dueAt)
            ? $this->renderText($definition->dueAt, $context)
            : $definition->dueAt;
    }
}
