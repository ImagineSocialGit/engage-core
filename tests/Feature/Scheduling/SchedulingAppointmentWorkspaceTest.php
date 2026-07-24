<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use App\Modules\Scheduling\Models\BookableService;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingAppointmentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03 12:00:00 UTC');
        $this->enableScheduling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_appointment_detail_requires_authentication_and_enabled_scheduling_module(): void
    {
        $appointment = Appointment::factory()->create();

        $this->get(route('crm.scheduling.appointments.show', $appointment))
            ->assertRedirect(route('login'));

        config()->set('modules.enabled', ['core']);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.appointments.show', $appointment))
            ->assertNotFound();
    }

    public function test_workspace_links_to_detail_with_attendees_and_lifecycle_history(): void
    {
        $user = User::factory()->create(['name' => 'Scheduling User']);
        $contact = Contact::factory()->create([
            'name' => 'Taylor Contact',
            'email' => 'taylor@example.test',
        ]);
        $service = $this->service(['name' => 'Planning Session']);
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00 UTC');
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'contact_id' => $contact->id,
            'status' => Appointment::STATUS_PENDING,
            'title' => 'Detailed Planning Session',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'source' => 'crm',
        ]);
        AppointmentAttendee::factory()->forContact($contact)->create([
            'appointment_id' => $appointment->id,
            'role' => 'primary',
            'status' => AppointmentAttendee::STATUS_INVITED,
        ]);
        AppointmentLifecycleEvent::factory()->actedBy($user)->create([
            'appointment_id' => $appointment->id,
            'event_key' => AppointmentLifecycleEvent::EVENT_CREATED,
            'from_status' => null,
            'to_status' => Appointment::STATUS_PENDING,
            'source' => 'crm',
            'reason' => 'crm_manual_create',
        ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.index'))
            ->assertOk()
            ->assertSee(route('crm.scheduling.appointments.show', $appointment));

        $this->actingAs($user)
            ->get(route('crm.scheduling.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Detailed Planning Session')
            ->assertSee('Planning Session')
            ->assertSee('Taylor Contact')
            ->assertSee('Invited')
            ->assertSee('Created')
            ->assertSee('crm_manual_create')
            ->assertSee('Confirm Appointment')
            ->assertSee('Cancel Appointment')
            ->assertDontSee('Mark Complete')
            ->assertDontSee('Mark No-show');
    }

    public function test_crm_confirmation_is_idempotent_and_records_actor_provenance(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $service = $this->service(['requires_confirmation' => true]);
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'contact_id' => $contact->id,
            'status' => Appointment::STATUS_PENDING,
            'starts_at' => CarbonImmutable::parse('2026-08-04 09:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-04 10:00:00 UTC'),
        ]);
        $attendee = AppointmentAttendee::factory()->forContact($contact)->create([
            'appointment_id' => $appointment->id,
            'role' => 'primary',
            'status' => AppointmentAttendee::STATUS_INVITED,
        ]);

        $route = route('crm.scheduling.appointments.confirm', $appointment);

        $this->actingAs($user)->patch($route)->assertRedirect(
            route('crm.scheduling.appointments.show', $appointment),
        );
        $this->actingAs($user)->patch($route)->assertRedirect();

        $appointment->refresh();
        $attendee->refresh();
        $event = AppointmentLifecycleEvent::query()
            ->where('appointment_id', $appointment->id)
            ->where('event_key', AppointmentLifecycleEvent::EVENT_CONFIRMED)
            ->sole();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->status);
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $attendee->status);
        $this->assertSame(1, AppointmentLifecycleEvent::query()
            ->where('appointment_id', $appointment->id)
            ->where('event_key', AppointmentLifecycleEvent::EVENT_CONFIRMED)
            ->count());
        $this->assertSame('crm', $event->source);
        $this->assertSame('crm_manual_confirm', $event->reason);
        $this->assertSame($user->getMorphClass(), $event->actor_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('crm_scheduling_appointment', data_get($event->context, 'surface'));
        $this->assertSame(1, AutomationEventOutboxEvent::query()
            ->where('event_key', 'appointment.confirmed')
            ->count());
    }

    public function test_cancellation_requires_reason_and_explicit_notice_override(): void
    {
        $user = User::factory()->create();
        $service = $this->service(['cancellation_notice_minutes' => 120]);
        $startsAt = CarbonImmutable::parse('2026-08-03 13:00:00 UTC');
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ]);
        $attendee = AppointmentAttendee::factory()->accepted()->create([
            'appointment_id' => $appointment->id,
            'role' => 'primary',
        ]);
        $route = route('crm.scheduling.appointments.cancel', $appointment);

        $this->actingAs($user)
            ->from(route('crm.scheduling.appointments.show', $appointment))
            ->patch($route, [])
            ->assertSessionHasErrors('cancellation_reason');

        $this->actingAs($user)
            ->patch($route, ['cancellation_reason' => 'Customer requested cancellation.'])
            ->assertSessionHas('error', 'The appointment cancellation notice window requires at least 120 minute(s).');

        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->refresh()->status);

        $this->actingAs($user)
            ->patch($route, [
                'cancellation_reason' => 'Customer requested cancellation.',
                'override_cancellation_notice' => true,
            ])
            ->assertSessionHas('success', 'Appointment canceled.');

        $appointment->refresh();
        $attendee->refresh();
        $event = AppointmentLifecycleEvent::query()
            ->where('appointment_id', $appointment->id)
            ->where('event_key', AppointmentLifecycleEvent::EVENT_CANCELED)
            ->sole();

        $this->assertSame(Appointment::STATUS_CANCELED, $appointment->status);
        $this->assertSame('Customer requested cancellation.', $appointment->cancellation_reason);
        $this->assertSame(AppointmentAttendee::STATUS_CANCELED, $attendee->status);
        $this->assertSame('cancel', data_get($event->context, 'action'));
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame(true, data_get(
            AutomationEventOutboxEvent::query()
                ->where('event_key', 'appointment.canceled')
                ->sole()
                ->meta,
            'force',
        ));
    }

    public function test_completion_and_no_show_are_time_gated_and_terminal_conflicts_are_rejected(): void
    {
        $user = User::factory()->create();
        $service = $this->service();
        $startsAt = CarbonImmutable::parse('2026-08-03 13:00:00 UTC');
        $appointment = Appointment::factory()->confirmed()->create([
            'bookable_service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ]);

        $this->actingAs($user)
            ->patch(route('crm.scheduling.appointments.complete', $appointment))
            ->assertSessionHas('error', 'Appointment status [completed] cannot be recorded before the appointment starts.');

        CarbonImmutable::setTestNow('2026-08-03 13:30:00 UTC');

        $this->actingAs($user)
            ->get(route('crm.scheduling.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Mark Complete')
            ->assertSee('Mark No-show');

        $this->actingAs($user)
            ->patch(route('crm.scheduling.appointments.complete', $appointment))
            ->assertSessionHas('success', 'Appointment marked complete.');

        $this->actingAs($user)
            ->patch(route('crm.scheduling.appointments.no-show', $appointment))
            ->assertSessionHas('error', 'Appointment status [completed] cannot transition to [no_show].');

        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->refresh()->status);
        $this->assertSame(1, AppointmentLifecycleEvent::query()
            ->where('appointment_id', $appointment->id)
            ->where('event_key', AppointmentLifecycleEvent::EVENT_COMPLETED)
            ->count());
        $this->assertSame(0, AppointmentLifecycleEvent::query()
            ->where('appointment_id', $appointment->id)
            ->where('event_key', AppointmentLifecycleEvent::EVENT_NO_SHOW)
            ->count());
    }

    private function enableScheduling(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function service(array $attributes = []): BookableService
    {
        return BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
            ...$attributes,
        ]);
    }
}