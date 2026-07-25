<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingAppointmentRescheduleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03 12:00:00 UTC');
        config()->set('scheduling.slot_offers.ttl_seconds', 300);
        config()->set('scheduling.booking_holds.ttl_seconds', 600);
        $this->enableScheduling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_reschedule_workspace_requires_authentication_and_enabled_scheduling_module(): void
    {
        $appointment = Appointment::factory()->create();
        $route = route('crm.scheduling.appointments.reschedule', $appointment);

        $this->get($route)->assertRedirect(route('login'));

        config()->set('modules.enabled', ['core']);

        $this->actingAs(User::factory()->create())
            ->get($route)
            ->assertNotFound();
    }

    public function test_detail_links_to_reschedule_workspace_and_excludes_the_current_target(): void
    {
        $user = User::factory()->create();
        [$service, $host] = $this->hostedService([
            'name' => 'Planning Session',
        ]);
        $currentStart = CarbonImmutable::parse('2026-08-04 09:00:00 UTC');
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'title' => 'Current Planning Session',
            'starts_at' => $currentStart,
            'ends_at' => $currentStart->addHour(),
        ]);
        $this->availability(
            service: $service,
            host: $host,
            startsAt: $currentStart,
            endsAt: $currentStart->addHours(3),
        );

        $this->actingAs($user)
            ->get(route('crm.scheduling.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Reschedule Appointment')
            ->assertSee(route('crm.scheduling.appointments.reschedule', $appointment));

        $this->actingAs($user)
            ->get(route('crm.scheduling.appointments.reschedule', [
                'appointment' => $appointment,
                'scheduling_host_id' => $host->id,
                'date' => '2026-08-04',
            ]))
            ->assertOk()
            ->assertSee('Current Planning Session')
            ->assertSee('10:00 AM–11:00 AM')
            ->assertSee('11:00 AM–12:00 PM')
            ->assertDontSee('value="'.$currentStart->toISOString().'"', false);
    }

    public function test_crm_reschedule_changes_host_preserves_confirmation_and_records_provenance(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        [$service, $originalHost] = $this->hostedService([
            'requires_confirmation' => true,
        ]);
        $replacementHost = $this->assignHost($service);
        $originalStart = CarbonImmutable::parse('2026-08-04 09:00:00 UTC');
        $replacementStart = CarbonImmutable::parse('2026-08-04 11:00:00 UTC');
        $original = Appointment::factory()->confirmed()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $originalHost->id,
            'contact_id' => $contact->id,
            'starts_at' => $originalStart,
            'ends_at' => $originalStart->addHour(),
        ]);
        AppointmentAttendee::factory()->forContact($contact)->accepted()->create([
            'appointment_id' => $original->id,
            'role' => 'primary',
        ]);
        $this->availability(
            service: $service,
            host: $replacementHost,
            startsAt: $replacementStart,
            endsAt: $replacementStart->addHour(),
        );
        $key = (string) Str::uuid();

        $response = $this->actingAs($user)
            ->from(route('crm.scheduling.appointments.reschedule', $original))
            ->post(route('crm.scheduling.appointments.reschedule.store', $original), [
                'scheduling_host_id' => $replacementHost->id,
                'starts_at' => $replacementStart->toISOString(),
                'idempotency_key' => $key,
                'reschedule_reason' => 'Contact requested a later time.',
                'preserve_confirmation' => true,
            ]);

        $replacement = Appointment::query()
            ->where('rescheduled_from_id', $original->id)
            ->sole();

        $response
            ->assertRedirect(route('crm.scheduling.appointments.show', $replacement))
            ->assertSessionHas('success', 'Appointment rescheduled.');

        $original->refresh();
        $event = AppointmentLifecycleEvent::query()
            ->where('appointment_id', $replacement->id)
            ->where('event_key', AppointmentLifecycleEvent::EVENT_RESCHEDULED)
            ->sole();

        $this->assertSame(Appointment::STATUS_CANCELED, $original->status);
        $this->assertSame('Contact requested a later time.', $original->cancellation_reason);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $replacement->status);
        $this->assertSame($replacementHost->id, $replacement->scheduling_host_id);
        $this->assertTrue($replacement->starts_at->equalTo($replacementStart));
        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertSame(
            AppointmentAttendee::STATUS_ACCEPTED,
            $replacement->attendees()->where('role', 'primary')->sole()->status,
        );
        $this->assertSame('crm', $event->source);
        $this->assertSame('Contact requested a later time.', $event->reason);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame(
            'crm_scheduling_appointment_reschedule',
            data_get($event->context, 'surface'),
        );
        $this->assertSame(true, data_get($event->context, 'requested_preserve_confirmation'));
        $this->assertSame(1, BookingHold::query()->count());
        $this->assertSame(1, BookableSlotOffer::query()->count());
        $this->assertSame(1, AutomationEventOutboxEvent::query()
            ->where('event_key', 'appointment.rescheduled')
            ->count());
    }

    public function test_matching_crm_reschedule_replay_returns_the_existing_replacement(): void
    {
        $user = User::factory()->create();
        $service = $this->service();
        $originalStart = CarbonImmutable::parse('2026-08-04 09:00:00 UTC');
        $replacementStart = CarbonImmutable::parse('2026-08-04 13:00:00 UTC');
        $original = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => null,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => $originalStart,
            'ends_at' => $originalStart->addHour(),
        ]);
        AppointmentAttendee::factory()->accepted()->create([
            'appointment_id' => $original->id,
            'role' => 'primary',
        ]);
        $this->availability(
            service: $service,
            host: null,
            startsAt: $replacementStart,
            endsAt: $replacementStart->addHour(),
        );
        $key = (string) Str::uuid();
        $payload = [
            'scheduling_host_id' => null,
            'starts_at' => $replacementStart->toISOString(),
            'idempotency_key' => $key,
            'reschedule_reason' => 'Move to the afternoon.',
        ];
        $route = route('crm.scheduling.appointments.reschedule.store', $original);

        $this->actingAs($user)->post($route, $payload)->assertRedirect();
        $replacement = Appointment::query()
            ->where('rescheduled_from_id', $original->id)
            ->sole();

        $this->actingAs($user)
            ->post($route, $payload)
            ->assertRedirect(route('crm.scheduling.appointments.show', $replacement));

        $this->assertSame(2, Appointment::query()->count());
        $this->assertSame(1, BookingHold::query()->count());
        $this->assertSame(1, BookableSlotOffer::query()->count());
        $this->assertSame(1, AppointmentLifecycleEvent::query()
            ->where('appointment_id', $replacement->id)
            ->where('event_key', AppointmentLifecycleEvent::EVENT_RESCHEDULED)
            ->count());
    }

    public function test_reschedule_notice_failure_rolls_back_offer_and_hold_until_explicit_override(): void
    {
        $user = User::factory()->create();
        $service = $this->service([
            'reschedule_notice_minutes' => 120,
        ]);
        $originalStart = CarbonImmutable::parse('2026-08-03 13:00:00 UTC');
        $replacementStart = CarbonImmutable::parse('2026-08-04 14:00:00 UTC');
        $original = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => $originalStart,
            'ends_at' => $originalStart->addHour(),
        ]);
        $this->availability(
            service: $service,
            host: null,
            startsAt: $replacementStart,
            endsAt: $replacementStart->addHour(),
        );
        $route = route('crm.scheduling.appointments.reschedule.store', $original);

        $this->actingAs($user)
            ->from(route('crm.scheduling.appointments.reschedule', $original))
            ->post($route, [
                'starts_at' => $replacementStart->toISOString(),
                'idempotency_key' => (string) Str::uuid(),
                'reschedule_reason' => 'Late customer request.',
            ])
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(0, BookingHold::query()->count());
        $this->assertSame(0, BookableSlotOffer::query()->count());

        $this->actingAs($user)
            ->post($route, [
                'starts_at' => $replacementStart->toISOString(),
                'idempotency_key' => (string) Str::uuid(),
                'reschedule_reason' => 'Late customer request.',
                'override_reschedule_notice' => true,
            ])
            ->assertSessionHas('success', 'Appointment rescheduled.');

        $outbox = AutomationEventOutboxEvent::query()
            ->where('event_key', 'appointment.rescheduled')
            ->sole();

        $this->assertSame(true, data_get($outbox->meta, 'force'));
    }

    public function test_reschedule_rejects_unassigned_hosts_stale_slots_and_caller_authored_internals(): void
    {
        $user = User::factory()->create();
        [$service, $assignedHost] = $this->hostedService();
        $otherHost = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);
        $originalStart = CarbonImmutable::parse('2026-08-04 09:00:00 UTC');
        $replacementStart = CarbonImmutable::parse('2026-08-04 11:00:00 UTC');
        $original = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $assignedHost->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => $originalStart,
            'ends_at' => $originalStart->addHour(),
        ]);
        $this->availability(
            service: $service,
            host: $assignedHost,
            startsAt: $replacementStart,
            endsAt: $replacementStart->addHour(),
        );
        $route = route('crm.scheduling.appointments.reschedule.store', $original);

        $this->actingAs($user)
            ->post($route, [
                'scheduling_host_id' => $otherHost->id,
                'starts_at' => $replacementStart->toISOString(),
                'idempotency_key' => (string) Str::uuid(),
                'reschedule_reason' => 'Use another host.',
            ])
            ->assertSessionHasErrors('starts_at');

        $this->actingAs($user)
            ->post($route, [
                'scheduling_host_id' => $assignedHost->id,
                'starts_at' => $replacementStart->addHours(4)->toISOString(),
                'idempotency_key' => (string) Str::uuid(),
                'reschedule_reason' => 'Use a stale time.',
            ])
            ->assertSessionHasErrors('starts_at');

        $this->actingAs($user)
            ->post($route, [
                'scheduling_host_id' => $assignedHost->id,
                'starts_at' => $replacementStart->toISOString(),
                'idempotency_key' => (string) Str::uuid(),
                'reschedule_reason' => 'Attempt forged state.',
                'status' => Appointment::STATUS_COMPLETED,
                'bookable_service_id' => $service->id,
            ])
            ->assertSessionHasErrors(['status', 'bookable_service_id']);

        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(0, BookingHold::query()->count());
        $this->assertSame(0, BookableSlotOffer::query()->count());
    }

    public function test_terminal_or_already_replaced_appointments_do_not_expose_rescheduling(): void
    {
        $user = User::factory()->create();
        $service = $this->service();
        $terminal = Appointment::factory()->completed()->create([
            'bookable_service_id' => $service->id,
        ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.appointments.show', $terminal))
            ->assertOk()
            ->assertDontSee('Reschedule Appointment');

        $this->actingAs($user)
            ->get(route('crm.scheduling.appointments.reschedule', $terminal))
            ->assertRedirect(route('crm.scheduling.appointments.show', $terminal))
            ->assertSessionHas('error', 'This appointment is no longer eligible for rescheduling.');
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
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'reschedule_notice_minutes' => 0,
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedService(array $attributes = []): array
    {
        $service = $this->service($attributes);
        $host = $this->assignHost($service);

        return [$service, $host];
    }

    private function assignHost(BookableService $service): SchedulingHost
    {
        $host = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'is_active' => true,
        ]);

        return $host;
    }

    private function availability(
        BookableService $service,
        ?SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): SchedulingAvailabilityWindow {
        $factory = SchedulingAvailabilityWindow::factory()
            ->absolute($startsAt, $endsAt);

        $factory = $host instanceof SchedulingHost
            ? $factory->forServiceAndHost($service, $host)
            : $factory->serviceWide($service);

        return $factory->create([
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);
    }
}