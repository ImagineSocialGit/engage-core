<?php

namespace App\Support\ModuleIntegrations\Scheduling\Simple;

use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Scheduling\Automation\AppointmentAutomationTriggerAuthoringContributor;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Services\AutomationEventConsumer;
use App\Support\Modules\ModuleManager;
use Closure;

final class ApplySimpleAppointmentAfterBookingActions
{
    private const CONSUMER_PREFIX = 'scheduling.after_booking';

    public function __construct(
        private readonly AutomationEventConsumer $events,
        private readonly ModuleManager $modules,
    ) {}

    public function handle(AutomationEventRecorded $recorded): void
    {
        $event = $recorded->event;

        if ($event->eventKey !== AppointmentAutomationTriggerAuthoringContributor::EVENT_SCHEDULED) {
            return;
        }

        $enabled = $this->modules->enabledKeysWithDependencies();

        if (in_array('flow_routes', $enabled, true)) {
            return;
        }

        $appointment = $this->appointment($recorded);

        if (! $appointment instanceof Appointment) {
            return;
        }

        $service = BookableService::query()->find($appointment->bookable_service_id);
        $contact = $appointment->contact_id !== null
            ? Contact::query()->find($appointment->contact_id)
            : null;
        $configuration = is_array(data_get(
            $service?->meta,
            SimpleAppointmentAfterBookingWorkspace::META_KEY,
        ))
            ? data_get($service?->meta, SimpleAppointmentAfterBookingWorkspace::META_KEY)
            : [];

        if (! $service instanceof BookableService
            || ! $contact instanceof Contact
            || $configuration === []
        ) {
            return;
        }

        $tag = $this->nullableString($configuration['tag'] ?? null);

        if ($tag !== null) {
            $this->consume(
                recorded: $recorded,
                action: 'add_tag',
                effect: fn () => ContactTag::query()->firstOrCreate([
                    'contact_id' => $contact->getKey(),
                    'tag' => $tag,
                ]),
            );
        }

        $statusKey = $this->nullableString($configuration['contact_status_key'] ?? null);

        if ($statusKey !== null
            && in_array('workflow', $enabled, true)
            && app()->bound(UpdatesContactStatus::class)
        ) {
            $status = ContactStatus::query()
                ->active()
                ->where('key', $statusKey)
                ->first();

            if ($status instanceof ContactStatus) {
                $this->consume(
                    recorded: $recorded,
                    action: 'change_status',
                    effect: fn () => app(UpdatesContactStatus::class)->handle(
                        contact: $contact,
                        status: $status,
                        reason: 'scheduling_after_booking',
                        source: 'scheduling',
                        meta: [
                            'appointment_id' => (int) $appointment->getKey(),
                            'bookable_service_id' => (int) $service->getKey(),
                            'automation_event_id' => $recorded->event->eventId,
                        ],
                    ),
                );
            }
        }

        $taskTemplateKey = $this->nullableString($configuration['task_template_key'] ?? null);

        if ($taskTemplateKey !== null && in_array('tasks', $enabled, true)) {
            $template = TaskTemplate::query()
                ->active()
                ->forKey($taskTemplateKey)
                ->first();

            if ($template instanceof TaskTemplate) {
                $this->consume(
                    recorded: $recorded,
                    action: 'create_task',
                    effect: fn () => app(CreateTaskFromTemplateAction::class)->handle(
                        $template,
                        [
                            'links' => [
                                [
                                    'linkable' => $appointment,
                                    'role' => TaskLink::ROLE_SUBJECT,
                                ],
                                [
                                    'linkable' => $contact,
                                    'role' => TaskLink::ROLE_CONTEXT,
                                ],
                            ],
                            'link_context' => [
                                TaskTemplate::LINK_SOURCE_CURRENT_CONTACT => $contact,
                                TaskTemplate::LINK_SOURCE_CURRENT_SUBJECT => $appointment,
                            ],
                            'source' => Task::SOURCE_MODULE,
                            'meta' => [
                                'scheduling_after_booking' => [
                                    'appointment_id' => (int) $appointment->getKey(),
                                    'bookable_service_id' => (int) $service->getKey(),
                                    'automation_event_id' => $recorded->event->eventId,
                                ],
                            ],
                        ],
                    ),
                );
            }
        }
    }

    private function appointment(AutomationEventRecorded $recorded): ?Appointment
    {
        $appointmentId = data_get($recorded->event->payload, 'appointment_id');

        if (! is_numeric($appointmentId)) {
            $appointmentId = $recorded->event->subjectId;
        }

        if (! is_numeric($appointmentId)) {
            return null;
        }

        return Appointment::query()->find((int) $appointmentId);
    }

    private function consume(
        AutomationEventRecorded $recorded,
        string $action,
        Closure $effect,
    ): void {
        if (! $recorded->event->hasDurableIdentity()) {
            return;
        }

        $this->events->consume(
            eventId: (string) $recorded->event->eventId,
            consumer: self::CONSUMER_PREFIX.'.'.$action,
            effect: $effect,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}