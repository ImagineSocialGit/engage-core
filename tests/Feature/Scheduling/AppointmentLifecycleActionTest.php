<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\CancelAppointmentAction;
use App\Modules\Scheduling\Actions\CompleteAppointmentAction;
use App\Modules\Scheduling\Actions\ConfirmAppointmentAction;
use App\Modules\Scheduling\Actions\ConvertBookingHoldToAppointmentAction;
use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Actions\MarkAppointmentNoShowAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentLifecycleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-22 15:00:00', 'UTC'),
        );

        config()->set('scheduling.slot_offers.ttl_seconds', 300);
        config()->set('scheduling.booking_holds.ttl_seconds', 600);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_confirmation_required_conversion_creates_invited_attendee_and_neutral_created_event(): void
    {
        [$service, $host] = $this->hostedService([
            'requires_confirmation' => true,
        ]);
        $this->absoluteAvailability($service, $host);
        $hold = $this->holdSlot($this->slots($service, $host)[0], 'pending-conversion');
        $contact = Contact::factory()->create([
            'name' => 'Pending Person',
            'email' => 'pending@example.test',
            'phone' => '15555550123',
        ]);

        $appointment = app(ConvertBookingHoldToAppointmentAction::class)->handle(
            $hold->hold_id,
            new AppointmentBookingData(
                contact: $contact,
                source: 'public_booking',
            ),
        );
        $attendee = $appointment->attendees()->sole();
        $lifecycle = $appointment->lifecycleEvents()->sole();
        $outbox = AutomationEventOutboxEvent::query()->sole();

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->status);
        $this->assertSame(AppointmentAttendee::STATUS_INVITED, $attendee->status);
        $this->assertNull($attendee->responded_at);
        $this->assertSame(AppointmentLifecycleEvent::EVENT_CREATED, $lifecycle->event_key);
        $this->assertSame(Appointment::STATUS_PENDING, $lifecycle->to_status);
        $this->assertSame('appointment.created', $outbox->event_key);
        $this->assertSame($appointment->id, (int) $outbox->subject_id);
        $this->assertSame($contact->id, $outbox->contact_id);
        $this->assertSame($appointment->id, data_get($outbox->payload, 'appointment_id'));
        $this->assertSame(Appointment::STATUS_PENDING, data_get($outbox->payload, 'status'));
        $this->assertStringNotContainsString(
            'pending@example.test',
            json_encode($outbox->payload, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            '15555550123',
            json_encode($outbox->payload, JSON_THROW_ON_ERROR),
        );
    }

    public function test_confirmation_updates_only_the_correlated_attendee_and_replays_idempotently(): void
    {
        $contact = Contact::factory()->create();
        $appointment = Appointment::factory()->create([
            'contact_id' => $contact->id,
            'status' => Appointment::STATUS_PENDING,
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
            'ends_at' => CarbonImmutable::now('UTC')->addDay()->addHour(),
        ]);
        $primary = AppointmentAttendee::factory()
            ->forContact($contact)
            ->create([
                'appointment_id' => $appointment->id,
                'role' => 'primary',
                'status' => AppointmentAttendee::STATUS_INVITED,
            ]);
        $other = AppointmentAttendee::factory()->create([
            'appointment_id' => $appointment->id,
            'role' => 'attendee',
            'status' => AppointmentAttendee::STATUS_INVITED,
        ]);
        $occurredAt = CarbonImmutable::now('UTC');
        $context = new AppointmentLifecycleContext(
            attendee: $primary,
            source: 'sms_reply',
            reason: 'appointment_confirmation_reply_accepted',
            occurredAt: $occurredAt,
            context: [
                'correlation_id' => 'appointment-confirmation-42',
                'response_key' => 'yes',
            ],
        );
        $action = app(ConfirmAppointmentAction::class);

        $confirmed = $action->handle($appointment, $context);
        $replayed = $action->handle($appointment, $context);

        $this->assertSame($confirmed->id, $replayed->id);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $confirmed->status);
        $this->assertTrue($confirmed->confirmed_at->equalTo($occurredAt));
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $primary->fresh()->status);
        $this->assertTrue($primary->fresh()->responded_at->equalTo($occurredAt));
        $this->assertSame(AppointmentAttendee::STATUS_INVITED, $other->fresh()->status);
        $this->assertNull($other->fresh()->responded_at);
        $this->assertSame(1, $appointment->lifecycleEvents()->count());
        $this->assertSame(1, AutomationEventOutboxEvent::query()->count());

        $event = $appointment->lifecycleEvents()->sole();
        $outbox = AutomationEventOutboxEvent::query()->sole();

        $this->assertSame(AppointmentLifecycleEvent::EVENT_CONFIRMED, $event->event_key);
        $this->assertSame(Appointment::STATUS_PENDING, $event->from_status);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $event->to_status);
        $this->assertSame('sms_reply', $event->source);
        $this->assertSame($primary->id, data_get($event->context, 'appointment_attendee_id'));
        $this->assertSame('appointment-confirmation-42', data_get($event->context, 'correlation_id'));
        $this->assertSame('appointment.confirmed', $outbox->event_key);
        $this->assertSame($primary->id, data_get($outbox->payload, 'appointment_attendee_id'));
        $this->assertSame('sms_reply', data_get($outbox->meta, 'source'));
    }

    public function test_confirmation_rejects_an_attendee_from_another_appointment_without_partial_changes(): void
    {
        $appointment = Appointment::factory()->create([
            'status' => Appointment::STATUS_PENDING,
        ]);
        $otherAppointment = Appointment::factory()->create([
            'status' => Appointment::STATUS_PENDING,
        ]);
        $foreignAttendee = AppointmentAttendee::factory()->create([
            'appointment_id' => $otherAppointment->id,
            'status' => AppointmentAttendee::STATUS_INVITED,
        ]);

        try {
            app(ConfirmAppointmentAction::class)->handle(
                $appointment,
                new AppointmentLifecycleContext(
                    attendee: $foreignAttendee,
                    source: 'sms_reply',
                ),
            );
            $this->fail('Expected a foreign appointment attendee to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('does not belong', $exception->getMessage());
        }

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->fresh()->status);
        $this->assertSame(AppointmentAttendee::STATUS_INVITED, $foreignAttendee->fresh()->status);
        $this->assertSame(0, AppointmentLifecycleEvent::query()->count());
        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());
    }

    public function test_cancellation_notice_is_enforced_unless_forced_and_nonterminal_attendees_are_canceled(): void
    {
        $actor = User::factory()->create();
        $service = BookableService::factory()->create([
            'cancellation_notice_minutes' => 120,
        ]);
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'confirmed_at' => CarbonImmutable::now('UTC')->subDay(),
            'starts_at' => CarbonImmutable::now('UTC')->addHour(),
            'ends_at' => CarbonImmutable::now('UTC')->addHours(2),
        ]);
        $invited = AppointmentAttendee::factory()->create([
            'appointment_id' => $appointment->id,
            'status' => AppointmentAttendee::STATUS_INVITED,
        ]);
        $accepted = AppointmentAttendee::factory()->accepted()->create([
            'appointment_id' => $appointment->id,
        ]);
        $declined = AppointmentAttendee::factory()->create([
            'appointment_id' => $appointment->id,
            'status' => AppointmentAttendee::STATUS_DECLINED,
            'responded_at' => CarbonImmutable::now('UTC')->subDay(),
        ]);
        $action = app(CancelAppointmentAction::class);

        try {
            $action->handle(
                $appointment,
                new AppointmentLifecycleContext(
                    actor: $actor,
                    source: 'crm',
                    reason: 'Client requested cancellation.',
                ),
            );
            $this->fail('Expected a late cancellation to require force authorization.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('120 minute', $exception->getMessage());
        }

        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);
        $this->assertSame(0, AppointmentLifecycleEvent::query()->count());

        $canceled = $action->handle(
            $appointment,
            new AppointmentLifecycleContext(
                actor: $actor,
                source: 'crm',
                reason: 'Client requested cancellation.',
                force: true,
                context: [
                    'surface' => 'crm_appointment_show',
                ],
            ),
        );
        $replayed = $action->handle(
            $appointment,
            new AppointmentLifecycleContext(
                actor: $actor,
                source: 'crm',
                reason: 'A retry must not create another event.',
                force: true,
            ),
        );

        $this->assertSame($canceled->id, $replayed->id);
        $this->assertSame(Appointment::STATUS_CANCELED, $canceled->status);
        $this->assertSame('Client requested cancellation.', $canceled->cancellation_reason);
        $this->assertNotNull($canceled->canceled_at);
        $this->assertSame(AppointmentAttendee::STATUS_CANCELED, $invited->fresh()->status);
        $this->assertSame(AppointmentAttendee::STATUS_CANCELED, $accepted->fresh()->status);
        $this->assertNotNull($invited->fresh()->canceled_at);
        $this->assertNotNull($accepted->fresh()->canceled_at);
        $this->assertSame(AppointmentAttendee::STATUS_DECLINED, $declined->fresh()->status);
        $this->assertNull($declined->fresh()->canceled_at);
        $this->assertSame(1, $appointment->lifecycleEvents()->count());
        $this->assertSame(1, AutomationEventOutboxEvent::query()->count());

        $event = $appointment->lifecycleEvents()->sole();
        $outbox = AutomationEventOutboxEvent::query()->sole();

        $this->assertSame(AppointmentLifecycleEvent::EVENT_CANCELED, $event->event_key);
        $this->assertSame(2, data_get($event->context, 'canceled_attendee_count'));
        $this->assertSame('crm_appointment_show', data_get($event->context, 'surface'));
        $this->assertSame('appointment.canceled', $outbox->event_key);
        $this->assertTrue((bool) data_get($outbox->meta, 'force'));
    }

    public function test_completed_and_no_show_outcomes_require_start_time_and_are_terminal_without_overwriting_attendees(): void
    {
        $startsAt = CarbonImmutable::now('UTC')->addMinutes(10);
        $completedAppointment = Appointment::factory()->create([
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ]);
        $completedAttendee = AppointmentAttendee::factory()->accepted()->create([
            'appointment_id' => $completedAppointment->id,
        ]);
        $noShowAppointment = Appointment::factory()->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'confirmed_at' => CarbonImmutable::now('UTC')->subDay(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ]);
        $noShowAttendee = AppointmentAttendee::factory()->accepted()->create([
            'appointment_id' => $noShowAppointment->id,
        ]);

        foreach ([
            [app(CompleteAppointmentAction::class), $completedAppointment],
            [app(MarkAppointmentNoShowAction::class), $noShowAppointment],
        ] as [$action, $appointment]) {
            try {
                $action->handle($appointment);
                $this->fail('Expected an appointment outcome before the start time to be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('before the appointment starts', $exception->getMessage());
            }
        }

        CarbonImmutable::setTestNow($startsAt->addMinute());

        $completed = app(CompleteAppointmentAction::class)->handle(
            $completedAppointment,
            new AppointmentLifecycleContext(source: 'crm'),
        );
        $completedReplay = app(CompleteAppointmentAction::class)->handle(
            $completedAppointment,
            new AppointmentLifecycleContext(source: 'crm'),
        );
        $noShow = app(MarkAppointmentNoShowAction::class)->handle(
            $noShowAppointment,
            new AppointmentLifecycleContext(source: 'crm'),
        );

        $this->assertSame($completed->id, $completedReplay->id);
        $this->assertSame(Appointment::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(Appointment::STATUS_NO_SHOW, $noShow->status);
        $this->assertNotNull($noShow->no_show_at);
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $completedAttendee->fresh()->status);
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $noShowAttendee->fresh()->status);
        $this->assertSame(2, AppointmentLifecycleEvent::query()->count());
        $this->assertSame(2, AutomationEventOutboxEvent::query()->count());
        $this->assertSame([
            'appointment.completed',
            'appointment.no_show',
        ], AutomationEventOutboxEvent::query()
            ->orderBy('event_key')
            ->pluck('event_key')
            ->all());

        try {
            app(MarkAppointmentNoShowAction::class)->handle($completedAppointment);
            $this->fail('Expected a conflicting terminal transition to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('completed', $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $serviceAttributes
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedService(array $serviceAttributes = []): array
    {
        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$serviceAttributes,
        ]);
        $host = SchedulingHost::factory()->create([
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'is_active' => true,
        ]);

        return [$service, $host];
    }

    private function absoluteAvailability(
        BookableService $service,
        SchedulingHost $host,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-07-23 09:00:00', 'UTC'),
                CarbonImmutable::parse('2026-07-23 11:00:00', 'UTC'),
            )
            ->forServiceAndHost($service, $host)
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
    }

    /**
     * @return array<int, BookableSlot>
     */
    private function slots(
        BookableService $service,
        SchedulingHost $host,
    ): array {
        return app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: CarbonImmutable::parse('2026-07-23 09:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-07-23 11:00:00', 'UTC'),
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
            ),
        );
    }

    private function holdSlot(BookableSlot $slot, string $idempotencyKey): BookingHold
    {
        $offer = app(IssueBookableSlotOfferAction::class)->handle($slot);

        return app(CreateBookingHoldAction::class)->handle(
            $offer->offer_id,
            $idempotencyKey,
        );
    }
}