<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Scheduling\Automation\AppointmentAutomationTriggerAuthoringContributor;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\ModuleIntegrations\Scheduling\Simple\ApplySimpleAppointmentAfterBookingActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingAfterBookingFallbackRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_fallback_applies_available_actions_once_per_durable_scheduled_event(): void
    {
        $this->enableSimpleFallbackModules();

        $contact = Contact::factory()->create();
        $status = ContactStatus::query()->create([
            'key' => 'appointment_booked',
            'name' => 'Appointment Booked',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $template = TaskTemplate::factory()
            ->currentContactSubject()
            ->create([
                'key' => 'appointment.follow_up',
                'name' => 'Appointment Follow Up',
                'due_offset_minutes' => 60,
            ]);
        $service = BookableService::factory()->create([
            'meta' => [
                'after_booking' => [
                    'version' => 1,
                    'tag' => 'appointment:booked',
                    'contact_status_key' => $status->key,
                    'task_template_key' => $template->key,
                ],
            ],
        ]);
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->getKey(),
            'contact_id' => $contact->getKey(),
            'status' => Appointment::STATUS_SCHEDULED,
        ]);

        $event = new AutomationEventRecorded(new AutomationEventData(
            eventKey: AppointmentAutomationTriggerAuthoringContributor::EVENT_SCHEDULED,
            contactId: (int) $contact->getKey(),
            subjectType: $appointment->getMorphClass(),
            subjectId: $appointment->getKey(),
            occurredAt: now(),
            payload: [
                'appointment_id' => (int) $appointment->getKey(),
                'bookable_service_id' => (int) $service->getKey(),
                'contact_id' => (int) $contact->getKey(),
            ],
            meta: ['source_module' => 'scheduling'],
            eventId: (string) Str::uuid(),
        ));

        $listener = app(ApplySimpleAppointmentAfterBookingActions::class);

        $listener->handle($event);
        $listener->handle($event);

        $this->assertDatabaseHas('contact_tags', [
            'contact_id' => $contact->getKey(),
            'tag' => 'appointment:booked',
        ]);

        $profile = ContactWorkflowProfile::query()
            ->where('contact_id', $contact->getKey())
            ->first();

        $this->assertNotNull($profile);
        $this->assertSame($status->getKey(), $profile->contact_status_id);

        $tasks = Task::query()
            ->where('task_template_key', $template->key)
            ->get();

        $this->assertCount(1, $tasks);
        $this->assertSame(
            (int) $appointment->getKey(),
            (int) data_get($tasks->first()->meta, 'scheduling_after_booking.appointment_id'),
        );

        $this->assertSame(
            3,
            \App\Support\AutomationEvents\Models\AutomationEventConsumerReceipt::query()
                ->where('event_id', $event->event->eventId)
                ->whereNotNull('completed_at')
                ->count(),
        );
    }

    public function test_simple_fallback_does_not_run_when_flow_routes_is_enabled(): void
    {
        $this->enableSimpleFallbackModules(flowRoutes: true);

        $contact = Contact::factory()->create();
        $service = BookableService::factory()->create([
            'meta' => [
                'after_booking' => [
                    'version' => 1,
                    'tag' => 'appointment:booked',
                ],
            ],
        ]);
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->getKey(),
            'contact_id' => $contact->getKey(),
        ]);

        app(ApplySimpleAppointmentAfterBookingActions::class)->handle(
            new AutomationEventRecorded(new AutomationEventData(
                eventKey: AppointmentAutomationTriggerAuthoringContributor::EVENT_SCHEDULED,
                contactId: (int) $contact->getKey(),
                subjectType: $appointment->getMorphClass(),
                subjectId: $appointment->getKey(),
                occurredAt: now(),
                payload: [
                    'appointment_id' => (int) $appointment->getKey(),
                ],
                eventId: (string) Str::uuid(),
            )),
        );

        $this->assertFalse(
            ContactTag::query()
                ->where('contact_id', $contact->getKey())
                ->where('tag', 'appointment:booked')
                ->exists(),
        );
    }

    private function enableSimpleFallbackModules(bool $flowRoutes = false): void
    {
        $enabled = [
            'core',
            'scheduling',
            'tasks',
            'workflow',
        ];

        if ($flowRoutes) {
            $enabled[] = 'flow_routes';
        }

        config()->set('modules.enabled', $enabled);

        $this->app->register(SchedulingModuleServiceProvider::class, true);
        $this->app->register(IntegrationsModuleServiceProvider::class, true);
    }
}