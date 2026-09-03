<?php

namespace App\Modules\Scheduling\Automation;

use App\Modules\Scheduling\Models\BookableService;
use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use Illuminate\Validation\Rule;

final class AppointmentAutomationTriggerAuthoringContributor implements AutomationTriggerAuthoringContributor
{
    public const KEY = 'scheduling.appointment_activity';
    public const EVENT_SCHEDULED = 'appointment.scheduled';
    public const BOOKABLE_SERVICE_EVENT_PATH = 'automation_event.payload.bookable_service_id';

    private const EVENTS = [
        self::EVENT_SCHEDULED => 'Appointment is scheduled',
        'appointment.confirmed' => 'Appointment is confirmed',
        'appointment.rescheduled' => 'Appointment is rescheduled',
        'appointment.canceled' => 'Appointment is canceled',
        'appointment.completed' => 'Appointment is completed',
        'appointment.no_show' => 'Contact does not attend',
    ];

    public function definitions(): iterable
    {
        yield new AutomationTriggerAuthoringDefinition(
            key: self::KEY,
            moduleKey: 'scheduling',
            name: 'Appointment activity occurs',
            description: 'Run when a selected appointment milestone occurs.',
            sortOrder: 80,
        );
    }

    public function available(string $authoringKey): bool
    {
        return $authoringKey === self::KEY;
    }

    public function fields(string $authoringKey): array
    {
        return [
            [
                'type' => 'select',
                'name' => 'appointment_event_key',
                'label' => 'Appointment activity',
                'required' => true,
                'placeholder' => 'Choose an activity',
                'options' => collect(self::EVENTS)->map(
                    fn (string $label, string $value): array => compact('value', 'label'),
                )->values()->all(),
            ],
            [
                'type' => 'select',
                'name' => 'bookable_service_id',
                'label' => 'Appointment type',
                'required' => false,
                'placeholder' => 'Any appointment type',
                'options' => BookableService::query()
                    ->where('status', BookableService::STATUS_ACTIVE)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (BookableService $service): array => [
                        'value' => (string) $service->getKey(),
                        'label' => (string) $service->name,
                    ])
                    ->all(),
                'help' => 'Leave this open to run for every appointment type.',
            ],
        ];
    }

    public function rules(string $authoringKey): array
    {
        return [
            'appointment_event_key' => ['required', 'string', Rule::in(array_keys(self::EVENTS))],
            'bookable_service_id' => [
                'nullable',
                'integer',
                Rule::exists('bookable_services', 'id')->where(
                    fn ($query) => $query->where('status', BookableService::STATUS_ACTIVE),
                ),
            ],
        ];
    }

    public function selection(string $authoringKey, array $input): AutomationTriggerSelection
    {
        $serviceId = is_numeric($input['bookable_service_id'] ?? null)
            ? (int) $input['bookable_service_id']
            : null;

        return new AutomationTriggerSelection(
            triggerType: 'automation_event',
            triggerKey: trim((string) $input['appointment_event_key']),
            entryConditions: $serviceId === null ? [] : [[
                'source' => 'execution_meta',
                'path' => self::BOOKABLE_SERVICE_EVENT_PATH,
                'operator' => 'equals',
                'value' => $serviceId,
            ]],
        );
    }
}