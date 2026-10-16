<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\Location;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_module_is_registered_without_being_enabled_by_default(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'campaigns',
            'broadcasts',
            'webinars',
            'integrations',
            'reporting',
        ]);

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('scheduling'));
        $this->assertFalse($modules->enabled('scheduling'));
        $this->assertSame(['core'], $modules->dependencies('scheduling'));
        $this->assertContains(SchedulingModuleServiceProvider::class, $modules->providers('scheduling'));
    }

    public function test_bookable_services_have_availability_windows_and_appointments(): void
    {
        $service = BookableService::factory()->create();

        $availabilityWindow = SchedulingAvailabilityWindow::factory()->create([
            'bookable_service_id' => $service->id,
            'timezone' => 'America/Chicago',
        ]);

        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
        ]);

        $this->assertTrue($service->availabilityWindows->contains($availabilityWindow));
        $this->assertTrue($service->appointments->contains($appointment));
        $this->assertSame('America/Chicago', $availabilityWindow->timezone);
    }

    public function test_appointment_defaults_are_available_without_refreshing_the_model(): void
    {
        $service = BookableService::factory()->create();
        $startsAt = now()->addDay()->startOfHour();

        $appointment = Appointment::query()->create([
            'bookable_service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
        ]);

        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);
        $this->assertSame('UTC', $appointment->timezone);
        $this->assertSame('manual', $appointment->source);
    }

    public function test_appointments_can_link_hosts_contacts_attendees_and_source_context(): void
    {
        $host = SchedulingHost::factory()->create();
        $contact = Contact::factory()->create();
        $sourceContext = Contact::factory()->create();

        $appointment = Appointment::factory()
            ->forSchedulingHost($host)
            ->forPrimaryAttendee($contact)
            ->fromSourceContext($sourceContext)
            ->create([
                'contact_id' => $contact->id,
                'source' => 'public_booking',
            ]);

        $attendee = AppointmentAttendee::factory()
            ->forContact($contact)
            ->accepted()
            ->create([
                'appointment_id' => $appointment->id,
            ]);

        $this->assertTrue($appointment->schedulingHost->is($host));
        $this->assertTrue($appointment->contact->is($contact));
        $this->assertTrue($appointment->primaryAttendee->is($contact));
        $this->assertTrue($appointment->sourceContext->is($sourceContext));
        $this->assertTrue($appointment->attendees->contains($attendee));
        $this->assertTrue($attendee->contact->is($contact));
        $this->assertTrue($attendee->attendee->is($contact));
    }

    public function test_appointments_can_optionally_use_a_saved_location_without_importing_location_in_scheduling(): void
    {
        $locationlessAppointment = Appointment::factory()->create([
            'location_reference_type' => null,
            'location_reference_id' => null,
            'location_type' => 'virtual',
            'location_details' => [
                'url' => 'https://example.test/meeting',
            ],
        ]);

        $location = Location::factory()->create([
            'name' => 'Main Office',
        ]);

        $appointment = Appointment::factory()
            ->forLocationReference($location)
            ->create([
                'location_type' => 'office',
                'location_details' => [
                    'room' => 'Consultation Room',
                ],
            ]);

        $this->assertNull($locationlessAppointment->locationReference);
        $this->assertSame('virtual', $locationlessAppointment->location_type);
        $this->assertSame('https://example.test/meeting', $locationlessAppointment->location_details['url']);
        $this->assertTrue($appointment->locationReference->is($location));
        $this->assertSame('office', $appointment->location_type);
        $this->assertSame('Consultation Room', $appointment->location_details['room']);
    }

    public function test_appointments_can_track_reschedule_lineage_and_durable_lifecycle_history(): void
    {
        $actor = User::factory()->create();
        $original = Appointment::factory()->canceled('Rescheduled by client')->create();

        $replacement = Appointment::factory()->create([
            'rescheduled_from_id' => $original->id,
        ]);

        $event = AppointmentLifecycleEvent::factory()
            ->actedBy($actor)
            ->create([
                'appointment_id' => $replacement->id,
                'event_id' => null,
                'event_key' => AppointmentLifecycleEvent::EVENT_RESCHEDULED,
                'from_status' => Appointment::STATUS_SCHEDULED,
                'to_status' => Appointment::STATUS_SCHEDULED,
                'source' => 'crm',
                'reason' => 'Client selected a new time.',
                'context' => [
                    'rescheduled_from_appointment_id' => $original->id,
                ],
            ]);

        $this->assertTrue($replacement->rescheduledFrom->is($original));
        $this->assertTrue($original->rescheduledAppointments->contains($replacement));
        $this->assertSame(Appointment::STATUS_CANCELED, $original->status);
        $this->assertSame('Rescheduled by client', $original->cancellation_reason);

        $this->assertTrue($replacement->lifecycleEvents->contains($event));
        $this->assertTrue($event->appointment->is($replacement));
        $this->assertTrue($event->actor->is($actor));
        $this->assertTrue(Str::isUuid($event->event_id));
        $this->assertSame($original->id, $event->context['rescheduled_from_appointment_id']);
        $this->assertNotNull($event->occurred_at);
    }

}