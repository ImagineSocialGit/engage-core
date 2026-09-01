<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentTaskAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    private const EVENTS = [
        'appointment.scheduled',
        'appointment.confirmed',
        'appointment.rescheduled',
    ];

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'create_appointment_task',
            moduleKey: 'scheduling',
            name: 'Create appointment-related task',
            description: 'Create a Task due before or after the Appointment and optionally assign it to the Appointment host.',
            tip: 'The due date follows the Appointment. If it is rescheduled, the open Task moves with it; if it is canceled, the open Task is canceled.',
            useCases: [
                'Create a preparation Task one day before an Appointment.',
                'Create a same-day follow-up Task assigned to the host.',
            ],
            typeLabel: 'Appointment task',
            genericLabels: ['appointment task', 'create appointment task'],
            generatedPrefixes: ['create appointment task from '],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return $this->appointmentRoute($context)
            && TaskTemplate::query()->active()->exists();
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        [$direction, $value, $unit] = $this->presentOffset((int) ($definition['offset_minutes'] ?? -1440));

        return [
            [
                'type' => 'notice',
                'title' => 'This Task follows the Appointment',
                'body' => 'Its due date is calculated from the Appointment start. Rescheduling moves pending work; cancellation removes pending work.',
            ],
            [
                'type' => 'select',
                'name' => 'task_template_key',
                'label' => 'Task Template',
                'required' => true,
                'value' => (string) ($definition['task_template_key'] ?? ''),
                'placeholder' => 'Choose a Task Template',
                'options' => TaskTemplate::query()->active()->orderBy('name')->get()
                    ->map(fn (TaskTemplate $template): array => [
                        'value' => (string) $template->key,
                        'label' => (string) ($template->name ?: $template->title),
                    ])->all(),
            ],
            [
                'type' => 'select',
                'name' => 'timing_direction',
                'label' => 'Task is due',
                'required' => true,
                'value' => $direction,
                'options' => [
                    ['value' => 'before', 'label' => 'Before the Appointment'],
                    ['value' => 'after', 'label' => 'After the Appointment starts'],
                ],
            ],
            [
                'type' => 'number',
                'name' => 'timing_value',
                'label' => 'Amount',
                'required' => true,
                'value' => $value,
                'min' => 0,
                'max' => 525600,
            ],
            [
                'type' => 'select',
                'name' => 'timing_unit',
                'label' => 'Unit',
                'required' => true,
                'value' => $unit,
                'options' => [
                    ['value' => 'minutes', 'label' => 'Minutes'],
                    ['value' => 'hours', 'label' => 'Hours'],
                    ['value' => 'days', 'label' => 'Days'],
                ],
            ],
            [
                'type' => 'checkbox',
                'name' => 'assign_to_host',
                'label' => 'Assign this Task to the Appointment host',
                'value' => (bool) ($definition['assign_to_host'] ?? true),
                'help' => 'When off, the Task Template controls assignment.',
            ],
        ];
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return [
            'task_template_key' => ['required', 'string', 'max:255'],
            'timing_direction' => ['required', Rule::in(['before', 'after'])],
            'timing_value' => ['required', 'integer', 'min:0', 'max:525600'],
            'timing_unit' => ['required', Rule::in(['minutes', 'hours', 'days'])],
            'assign_to_host' => ['required', 'boolean'],
        ];
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        $templateKey = trim((string) ($input['task_template_key'] ?? ''));

        if (! TaskTemplate::query()->active()->where('key', $templateKey)->exists()) {
            throw ValidationException::withMessages([
                'task_template_key' => 'Choose an active Task Template.',
            ]);
        }

        return [
            'task_template_key' => $templateKey,
            'offset_minutes' => $this->offsetMinutes($input),
            'assign_to_host' => filter_var($input['assign_to_host'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    public function pointName(string $pointType, string $fallback, array $input, array $definition, AutomationPointAuthoringContext $context): string
    {
        $custom = trim((string) ($input['name'] ?? ''));

        return $custom !== '' ? $custom : 'Create appointment task from '.$this->templateLabel((string) ($definition['task_template_key'] ?? ''));
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return $this->editorSummary($pointType, $definition, $context).'.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return 'Appointment task · '.$this->offsetLabel((int) ($definition['offset_minutes'] ?? 0)).' · '.((bool) ($definition['assign_to_host'] ?? true) ? 'Appointment host' : 'Template assignment');
    }

    private function appointmentRoute(AutomationPointAuthoringContext $context): bool
    {
        return $context->container instanceof FlowRoute
            && $context->container->trigger_type === FlowRoute::TRIGGER_AUTOMATION_EVENT
            && in_array($context->container->trigger_key, self::EVENTS, true);
    }

    private function offsetMinutes(array $input): int
    {
        $value = (int) ($input['timing_value'] ?? 0);
        $factor = match ($input['timing_unit'] ?? null) {
            'days' => 1440,
            'hours' => 60,
            default => 1,
        };
        $minutes = $value * $factor;

        return ($input['timing_direction'] ?? null) === 'before' ? -$minutes : $minutes;
    }

    private function presentOffset(int $minutes): array
    {
        $direction = $minutes < 0 ? 'before' : 'after';
        $absolute = abs($minutes);

        if ($absolute > 0 && $absolute % 1440 === 0) {
            return [$direction, intdiv($absolute, 1440), 'days'];
        }

        if ($absolute > 0 && $absolute % 60 === 0) {
            return [$direction, intdiv($absolute, 60), 'hours'];
        }

        return [$direction, $absolute, 'minutes'];
    }

    private function offsetLabel(int $minutes): string
    {
        [$direction, $value, $unit] = $this->presentOffset($minutes);

        return $value.' '.Str::plural(Str::singular($unit), $value).' '.$direction.' start';
    }

    private function templateLabel(string $key): string
    {
        $name = $key !== '' ? TaskTemplate::query()->where('key', $key)->value('name') : null;

        return is_string($name) && trim($name) !== '' ? $name : Str::headline($key ?: 'selected template');
    }
}