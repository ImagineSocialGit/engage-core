<?php

namespace App\Modules\Tasks\Automation;

use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Data\Automation\CreateTaskAutomationDefinition;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Services\TaskAssignmentStrategyResolver;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

class CreateTaskAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly CreateTaskFromTemplateAction $createTaskFromTemplate,
        private readonly TaskAssignmentStrategyResolver $assignmentStrategies,
    ) {}

    public function key(): string
    {
        return 'tasks.create_task';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = CreateTaskAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_create_task_automation_definition',
                output: [
                    'create_task_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        if ($definition->assignedToStrategy !== null
            && ! $this->assignmentStrategies->supports($definition->assignedToStrategy)
        ) {
            return AutomationActionResult::failed(
                reason: 'create_task_invalid_assigned_to_strategy',
                output: [
                    'create_task_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        $contact = $context->model(TaskTemplate::LINK_SOURCE_CURRENT_CONTACT);
        $subject = $context->model(TaskTemplate::LINK_SOURCE_CURRENT_SUBJECT)
            ?? $context->subject
            ?? $contact;

        try {
            $task = $this->createTaskFromTemplate->handle(
                $definition->taskTemplateKey,
                array_filter([
                    'links' => $this->links($contact, $subject),
                    'link_context' => [
                        TaskTemplate::LINK_SOURCE_CURRENT_CONTACT => $contact,
                        TaskTemplate::LINK_SOURCE_CURRENT_SUBJECT => $subject,
                    ],
                    'assigned_to_type' => $definition->assignedToType,
                    'assigned_to_id' => $definition->assignedToId,
                    'assigned_to_strategy' => $definition->assignedToStrategy,
                    'responsible_party' => $definition->responsibleParty,
                    'responsible_type' => $definition->responsibleType,
                    'responsible_id' => $definition->responsibleId,
                    'source' => Task::SOURCE_MODULE,
                    'title' => $definition->title,
                    'description' => $definition->description,
                    'due_at' => $definition->dueAt,
                    'due_offset_minutes' => $definition->dueOffsetMinutes,
                    'priority' => $definition->priority,
                ], static fn (mixed $value): bool => $value !== null),
            );
        } catch (ModelNotFoundException) {
            return AutomationActionResult::failed('task_template_not_found', output: [
                'create_task_definition' => $definition->toMetaPayload(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return AutomationActionResult::failed($exception->getMessage(), output: [
                'create_task_definition' => $definition->toMetaPayload(),
            ]);
        } catch (Throwable $exception) {
            return AutomationActionResult::failed('create_task_failed', output: [
                'create_task_definition' => $definition->toMetaPayload(),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }

        $task->loadMissing('links');

        return AutomationActionResult::completed(
            reason: 'task_created',
            artifacts: [$task],
            correlationKey: 'task.id',
            correlationType: 'task',
            correlation: [
                'task_id' => $task->getKey(),
                'task_template_id' => $task->task_template_id,
                'task_template_key' => $task->task_template_key,
            ],
            output: [
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
            ],
        );
    }

    /**
     * @return array<int, array{linkable: Model, role: string}>
     */
    private function links(?Model $contact, ?Model $subject): array
    {
        if (! $subject instanceof Model && ! $contact instanceof Model) {
            return [];
        }

        if (! $subject instanceof Model) {
            return [[
                'linkable' => $contact,
                'role' => TaskLink::ROLE_SUBJECT,
            ]];
        }

        if (! $contact instanceof Model || $subject->is($contact)) {
            return [[
                'linkable' => $subject,
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
}
