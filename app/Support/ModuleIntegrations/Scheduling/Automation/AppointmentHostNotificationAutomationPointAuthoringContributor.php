<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AppointmentHostNotificationAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    private const EVENTS = ['appointment.scheduled', 'appointment.confirmed', 'appointment.rescheduled'];

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'notify_appointment_host',
            moduleKey: 'scheduling',
            name: 'Notify appointment host',
            description: 'Send an internal reminder to the assigned host before or after the Appointment.',
            tip: 'The system uses the host’s internal-notification preferences. Rescheduling replaces a pending reminder; cancellation removes it.',
            useCases: [
                'Remind the host to prepare one day before an Appointment.',
                'Prompt the host to record notes after an Appointment starts.',
            ],
            typeLabel: 'Host notification',
            genericLabels: ['notify appointment host', 'host notification'],
            generatedPrefixes: ['notify appointment host'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return $context->container instanceof FlowRoute
            && $context->container->trigger_type === FlowRoute::TRIGGER_AUTOMATION_EVENT
            && in_array($context->container->trigger_key, self::EVENTS, true);
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        [$direction, $value, $unit] = $this->presentOffset((int) ($definition['offset_minutes'] ?? -1440));

        return [
            [
                'type' => 'notice',
                'title' => 'Internal reminder',
                'body' => 'Only the assigned Appointment host is notified, using that team member’s allowed internal-notification channel.',
            ],
            [
                'type' => 'text',
                'name' => 'subject',
                'label' => 'Notification subject',
                'required' => true,
                'value' => (string) ($definition['subject'] ?? 'Upcoming appointment'),
                'max' => 160,
            ],
            [
                'type' => 'textarea',
                'name' => 'message',
                'label' => 'Message',
                'required' => true,
                'value' => (string) ($definition['message'] ?? 'Please review the appointment details and prepare any needed follow-up.'),
                'rows' => 4,
                'help' => 'Appointment name, contact, start time, and a link are added automatically.',
            ],
            [
                'type' => 'select',
                'name' => 'timing_direction',
                'label' => 'Send',
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
        ];
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return [
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
            'timing_direction' => ['required', Rule::in(['before', 'after'])],
            'timing_value' => ['required', 'integer', 'min:0', 'max:525600'],
            'timing_unit' => ['required', Rule::in(['minutes', 'hours', 'days'])],
        ];
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        $value = (int) ($input['timing_value'] ?? 0);
        $factor = match ($input['timing_unit'] ?? null) {
            'days' => 1440,
            'hours' => 60,
            default => 1,
        };
        $minutes = $value * $factor;

        return [
            'offset_minutes' => ($input['timing_direction'] ?? null) === 'before' ? -$minutes : $minutes,
            'subject' => trim((string) ($input['subject'] ?? '')),
            'message' => trim((string) ($input['message'] ?? '')),
        ];
    }

    public function pointName(string $pointType, string $fallback, array $input, array $definition, AutomationPointAuthoringContext $context): string
    {
        $custom = trim((string) ($input['name'] ?? ''));

        return $custom !== '' ? $custom : 'Notify appointment host';
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return $this->editorSummary($pointType, $definition, $context).'.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return 'Host notification · '.$this->offsetLabel((int) ($definition['offset_minutes'] ?? 0));
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
}