<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateAppointmentTaskAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly CreateTaskFromTemplateAction $createTaskFromTemplate,
    ) {}

    public function key(): string
    {
        return 'scheduling.create_appointment_task';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $appointment = $context->subject;

        if (! $appointment instanceof Appointment || $appointment->starts_at === null) {
            return AutomationActionResult::failed('appointment_task_requires_appointment_subject');
        }

        $templateKey = trim((string) ($context->input['task_template_key'] ?? ''));
        $offsetMinutes = is_numeric($context->input['offset_minutes'] ?? null)
            ? (int) $context->input['offset_minutes']
            : null;
        $assignToHost = (bool) ($context->input['assign_to_host'] ?? true);

        if ($templateKey === '' || $offsetMinutes === null) {
            return AutomationActionResult::failed('appointment_task_definition_invalid');
        }

        $contact = $context->model(TaskTemplate::LINK_SOURCE_CURRENT_CONTACT);
        $assignee = null;

        if ($assignToHost) {
            $appointment->loadMissing('schedulingHost.hostable');
            $assignee = $appointment->schedulingHost?->hostable;

            if (! $assignee instanceof Model) {
                return AutomationActionResult::blocked('appointment_host_assignee_unavailable');
            }
        }

        try {
            $task = $this->createTaskFromTemplate->handle($templateKey, array_filter([
                'links' => $this->links($appointment, $contact),
                'link_context' => array_filter([
                    TaskTemplate::LINK_SOURCE_CURRENT_SUBJECT => $appointment,
                    TaskTemplate::LINK_SOURCE_CURRENT_CONTACT => $contact,
                ], fn (mixed $value): bool => $value instanceof Model),
                'assigned_to_type' => $assignee?->getMorphClass(),
                'assigned_to_id' => $assignee?->getKey(),
                'source' => Task::SOURCE_MODULE,
                'due_at' => CarbonImmutable::instance($appointment->starts_at)->utc()->addMinutes($offsetMinutes),
                'meta' => [
                    'appointment_automation' => [
                        'kind' => 'appointment_task',
                        'anchor' => 'appointment_start',
                        'appointment_id' => (int) $appointment->getKey(),
                        'offset_minutes' => $offsetMinutes,
                        'assign_to_host' => $assignToHost,
                        'flow_route_point_id' => $context->behaviorOwner?->getKey(),
                    ],
                ],
            ], fn (mixed $value): bool => $value !== null));
        } catch (Throwable $exception) {
            return AutomationActionResult::failed('appointment_task_creation_failed', output: [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }

        return AutomationActionResult::completed(
            reason: 'appointment_task_created',
            artifacts: [$task],
            correlationKey: 'task.id',
            correlationType: 'task',
            correlation: ['task_id' => $task->getKey()],
            output: ['task_id' => $task->getKey(), 'due_at' => $task->due_at?->toISOString()],
        );
    }

    private function links(Appointment $appointment, ?Model $contact): array
    {
        $links = [[
            'linkable' => $appointment,
            'role' => TaskLink::ROLE_SUBJECT,
        ]];

        if ($contact instanceof Model && ! $contact->is($appointment)) {
            $links[] = [
                'linkable' => $contact,
                'role' => TaskLink::ROLE_CONTEXT,
            ];
        }

        return $links;
    }
}