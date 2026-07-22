<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Scheduling\Actions\ConvertBookingHoldToAppointmentAction;
use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Actions\ReleaseBookingHoldAction;
use App\Modules\Scheduling\Actions\RescheduleAppointmentAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Data\AppointmentRescheduleData;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RescheduleAppointmentActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-27 12:00:00', 'UTC'),
        );

        config()->set('scheduling.slot_offers.ttl_seconds', 300);
        config()->set('scheduling.booking_holds.ttl_seconds', 172800);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_reschedule_atomically_replaces_the_appointment_and_preserves_vertical_identity(): void
    {
        [$service, $originalHost] = $this->hostedService([
            'requires_confirmation' => false,
            'reschedule_notice_minutes' => 60,
        ]);
        $replacementHost = $this->assignHost($service);
        $this->absoluteAvailability($service, $originalHost);
        $this->absoluteAvailability($service, $replacementHost);
        $owner = Contact::factory()->create([
            'name' => 'Taylor Owner',
            'email' => 'taylor@example.test',
            'phone' => '15555550111',
        ]);
        $pet = ContactImportBatch::factory()->create([
            'name' => 'Rover',
        ]);
        $sourceContext = ContactImportBatch::factory()->create([
            'name' => 'Pet-services request',
        ]);
        $actor = Contact::factory()->create([
            'name' => 'Scheduling Agent',
        ]);
        $original = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $originalHost->id,
            'contact_id' => $owner->id,
            'location_reference_type' => $sourceContext->getMorphClass(),
            'location_reference_id' => $sourceContext->id,
            'primary_attendee_type' => $pet->getMorphClass(),
            'primary_attendee_id' => $pet->id,
            'source_context_type' => $sourceContext->getMorphClass(),
            'source_context_id' => $sourceContext->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'title' => 'Pet consultation',
            'description' => 'Original appointment snapshot.',
            'location_type' => 'onsite',
            'location_details' => ['room' => 'A'],
            'timezone' => 'America/Chicago',
            'starts_at' => CarbonImmutable::parse('2026-07-28 09:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-28 10:00:00', 'UTC'),
            'confirmed_at' => CarbonImmutable::parse('2026-07-27 11:00:00', 'UTC'),
            'source' => 'public_booking',
            'meta' => [
                'vertical' => [
                    'request_key' => 'pet-consultation',
                ],
            ],
        ]);
        $primary = AppointmentAttendee::factory()->create([
            'appointment_id' => $original->id,
            'attendee_type' => $pet->getMorphClass(),
            'attendee_id' => $pet->id,
            'contact_id' => $owner->id,
            'name' => 'Rover',
            'email' => 'taylor@example.test',
            'phone' => '15555550111',
            'role' => 'primary',
            'status' => AppointmentAttendee::STATUS_ACCEPTED,
            'responded_at' => CarbonImmutable::parse('2026-07-27 11:00:00', 'UTC'),
            'meta' => ['subject' => ['kind' => 'pet']],
        ]);
        $secondary = AppointmentAttendee::factory()->create([
            'appointment_id' => $original->id,
            'contact_id' => Contact::factory()->create()->id,
            'name' => 'Additional participant',
            'role' => 'guest',
            'status' => AppointmentAttendee::STATUS_TENTATIVE,
            'responded_at' => CarbonImmutable::parse('2026-07-27 11:15:00', 'UTC'),
        ]);
        $hold = $this->rescheduleHold(
            service: $service,
            host: $replacementHost,
            original: $original,
            startsAt: '2026-07-28 11:00:00',
            idempotencyKey: 'atomic-reschedule',
        );
        $occurredAt = CarbonImmutable::now('UTC');
        $data = new AppointmentRescheduleData(
            holdId: $hold->hold_id,
            lifecycle: new AppointmentLifecycleContext(
                actor: $actor,
                source: 'crm',
                reason: 'customer_requested_new_time',
                occurredAt: $occurredAt,
                context: [
                    'surface' => 'crm_appointment_workspace',
                ],
            ),
        );
        $action = app(RescheduleAppointmentAction::class);

        $replacement = $action->handle($data);
        $replayed = $action->handle($data);

        $this->assertSame($replacement->id, $replayed->id);
        $this->assertSame(2, Appointment::query()->count());
        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertSame($service->id, $replacement->bookable_service_id);
        $this->assertSame($replacementHost->id, $replacement->scheduling_host_id);
        $this->assertSame($owner->id, $replacement->contact_id);
        $this->assertTrue($replacement->primaryAttendee->is($pet));
        $this->assertTrue($replacement->sourceContext->is($sourceContext));
        $this->assertTrue($replacement->locationReference->is($sourceContext));
        $this->assertSame(Appointment::STATUS_SCHEDULED, $replacement->status);
        $this->assertNull($replacement->confirmed_at);
        $this->assertSame('Pet consultation', $replacement->title);
        $this->assertSame('Original appointment snapshot.', $replacement->description);
        $this->assertSame('onsite', $replacement->location_type);
        $this->assertSame(['room' => 'A'], $replacement->location_details);
        $this->assertSame('America/Chicago', $replacement->timezone);
        $this->assertTrue($replacement->starts_at->equalTo(
            CarbonImmutable::parse('2026-07-28 11:00:00', 'UTC'),
        ));
        $this->assertTrue($replacement->ends_at->equalTo(
            CarbonImmutable::parse('2026-07-28 12:00:00', 'UTC'),
        ));
        $this->assertSame('crm', $replacement->source);
        $this->assertSame($actor->getMorphClass(), $replacement->created_by_type);
        $this->assertSame($actor->id, $replacement->created_by_id);
        $this->assertSame(
            'pet-consultation',
            data_get($replacement->meta, 'vertical.request_key'),
        );
        $this->assertSame(
            $original->id,
            data_get($replacement->meta, 'rescheduling.from_appointment_id'),
        );
        $this->assertSame(
            $hold->hold_id,
            data_get($replacement->meta, 'rescheduling.booking_hold_id'),
        );

        $replacementAttendees = $replacement->attendees()
            ->orderBy('id')
            ->get();
        $replacementPrimary = $replacementAttendees
            ->firstWhere('role', 'primary');
        $replacementSecondary = $replacementAttendees
            ->firstWhere('role', 'guest');

        $this->assertCount(2, $replacementAttendees);
        $this->assertTrue($replacementPrimary->attendee->is($pet));
        $this->assertSame($owner->id, $replacementPrimary->contact_id);
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $replacementPrimary->status);
        $this->assertTrue($replacementPrimary->responded_at->equalTo($occurredAt));
        $this->assertNull($replacementPrimary->joined_at);
        $this->assertNull($replacementPrimary->canceled_at);
        $this->assertSame(
            $primary->id,
            data_get($replacementPrimary->meta, 'rescheduling.from_appointment_attendee_id'),
        );
        $this->assertSame(AppointmentAttendee::STATUS_TENTATIVE, $replacementSecondary->status);
        $this->assertTrue($replacementSecondary->responded_at->equalTo($secondary->responded_at));

        $original->refresh();
        $primary->refresh();
        $secondary->refresh();

        $this->assertSame(Appointment::STATUS_CANCELED, $original->status);
        $this->assertTrue($original->canceled_at->equalTo($occurredAt));
        $this->assertSame('customer_requested_new_time', $original->cancellation_reason);
        $this->assertSame(AppointmentAttendee::STATUS_CANCELED, $primary->status);
        $this->assertSame(AppointmentAttendee::STATUS_CANCELED, $secondary->status);
        $this->assertTrue($primary->canceled_at->equalTo($occurredAt));
        $this->assertTrue($secondary->canceled_at->equalTo($occurredAt));
        $this->assertSame(0, $original->lifecycleEvents()->count());

        $event = $replacement->lifecycleEvents()->sole();

        $this->assertSame(AppointmentLifecycleEvent::EVENT_RESCHEDULED, $event->event_key);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $event->from_status);
        $this->assertSame(Appointment::STATUS_SCHEDULED, $event->to_status);
        $this->assertSame('crm', $event->source);
        $this->assertSame('customer_requested_new_time', $event->reason);
        $this->assertSame($original->id, data_get($event->context, 'original_appointment_id'));
        $this->assertSame($replacement->id, data_get($event->context, 'replacement_appointment_id'));
        $this->assertSame('crm_appointment_workspace', data_get($event->context, 'surface'));

        $outbox = AutomationEventOutboxEvent::query()
            ->where('event_key', 'appointment.rescheduled')
            ->sole();

        $this->assertSame($event->event_id, $outbox->event_id);
        $this->assertSame((string) $replacement->id, (string) $outbox->subject_id);
        $this->assertSame($original->id, data_get($outbox->payload, 'original_appointment_id'));
        $this->assertSame($replacement->id, data_get($outbox->payload, 'replacement_appointment_id'));
        $this->assertSame('2026-07-28T09:00:00.000000Z', data_get($outbox->payload, 'previous_starts_at'));
        $this->assertSame('2026-07-28T11:00:00.000000Z', data_get($outbox->payload, 'starts_at'));
        $this->assertArrayNotHasKey('email', $outbox->payload);
        $this->assertArrayNotHasKey('phone', $outbox->payload);

        $convertedHold = $hold->fresh();

        $this->assertSame(BookingHold::STATUS_CONVERTED, $convertedHold->status);
        $this->assertSame($replacement->id, $convertedHold->appointment_id);
        $this->assertTrue($convertedHold->converted_at->equalTo($occurredAt));
        $this->assertSame(1, $replacement->lifecycleEvents()->count());
        $this->assertSame(1, AutomationEventOutboxEvent::query()
            ->where('event_key', 'appointment.rescheduled')
            ->count());
    }

    public function test_confirmation_required_reschedules_reset_confirmation_unless_explicitly_preserved(): void
    {
        [$service, $host] = $this->hostedService([
            'requires_confirmation' => true,
        ]);
        $this->absoluteAvailability($service, $host);
        $original = $this->confirmedAppointment($service, $host, '2026-07-28 09:00:00');
        $originalPrimary = $this->primaryAttendee($original);
        $hold = $this->rescheduleHold(
            service: $service,
            host: $host,
            original: $original,
            startsAt: '2026-07-28 10:00:00',
            idempotencyKey: 'reset-confirmation',
        );

        $replacement = app(RescheduleAppointmentAction::class)->handle(
            new AppointmentRescheduleData($hold->hold_id),
        );
        $replacementPrimary = $replacement->attendees()->sole();

        $this->assertSame(Appointment::STATUS_PENDING, $replacement->status);
        $this->assertNull($replacement->confirmed_at);
        $this->assertSame(AppointmentAttendee::STATUS_INVITED, $replacementPrimary->status);
        $this->assertNull($replacementPrimary->responded_at);

        $secondOriginal = $this->confirmedAppointment($service, $host, '2026-07-28 11:00:00');
        $secondPrimary = $this->primaryAttendee($secondOriginal);
        $secondHold = $this->rescheduleHold(
            service: $service,
            host: $host,
            original: $secondOriginal,
            startsAt: '2026-07-28 12:00:00',
            idempotencyKey: 'preserve-confirmation',
        );
        $occurredAt = CarbonImmutable::now('UTC');

        $preserved = app(RescheduleAppointmentAction::class)->handle(
            new AppointmentRescheduleData(
                holdId: $secondHold->hold_id,
                lifecycle: new AppointmentLifecycleContext(
                    source: 'public_reschedule',
                    occurredAt: $occurredAt,
                ),
                preserveConfirmation: true,
            ),
        );
        $preservedPrimary = $preserved->attendees()->sole();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $preserved->status);
        $this->assertTrue($preserved->confirmed_at->equalTo($secondOriginal->confirmed_at));
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $preservedPrimary->status);
        $this->assertTrue($preservedPrimary->responded_at->equalTo($secondPrimary->responded_at));
        $this->assertNotSame($originalPrimary->id, $preservedPrimary->id);
    }

    public function test_reschedule_notice_is_enforced_and_explicit_force_can_override_it(): void
    {
        [$service, $host] = $this->hostedService([
            'reschedule_notice_minutes' => 60,
        ]);
        $this->absoluteAvailability($service, $host);
        $original = $this->confirmedAppointment($service, $host, '2026-07-28 10:00:00');
        $hold = $this->rescheduleHold(
            service: $service,
            host: $host,
            original: $original,
            startsAt: '2026-07-28 11:00:00',
            idempotencyKey: 'late-reschedule',
        );
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-28 09:30:00', 'UTC'),
        );

        try {
            app(RescheduleAppointmentAction::class)->handle(
                new AppointmentRescheduleData(
                    holdId: $hold->hold_id,
                    lifecycle: new AppointmentLifecycleContext(
                        source: 'public_reschedule',
                    ),
                ),
            );
            $this->fail('Expected late rescheduling to require explicit force authorization.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'reschedule notice window requires at least 60 minute(s)',
                $exception->getMessage(),
            );
        }

        $this->assertSame(Appointment::STATUS_CONFIRMED, $original->fresh()->status);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->fresh()->status);
        $this->assertSame(0, Appointment::query()
            ->where('rescheduled_from_id', $original->id)
            ->count());

        $replacement = app(RescheduleAppointmentAction::class)->handle(
            new AppointmentRescheduleData(
                holdId: $hold->hold_id,
                lifecycle: new AppointmentLifecycleContext(
                    source: 'crm',
                    reason: 'administrative_override',
                    force: true,
                ),
            ),
        );

        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertSame(Appointment::STATUS_CANCELED, $original->fresh()->status);
    }

    public function test_released_and_elapsed_holds_cannot_reschedule_an_appointment(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $releasedOriginal = $this->confirmedAppointment(
            $service,
            $host,
            '2026-07-28 09:00:00',
        );
        $releasedHold = $this->rescheduleHold(
            service: $service,
            host: $host,
            original: $releasedOriginal,
            startsAt: '2026-07-28 10:00:00',
            idempotencyKey: 'released-reschedule',
        );

        app(ReleaseBookingHoldAction::class)->handle($releasedHold->hold_id);

        try {
            app(RescheduleAppointmentAction::class)->handle(
                new AppointmentRescheduleData($releasedHold->hold_id),
            );
            $this->fail('Expected a released hold to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('released booking hold', $exception->getMessage());
        }

        $expiredOriginal = $this->confirmedAppointment(
            $service,
            $host,
            '2026-07-28 11:00:00',
        );
        $expiredHold = $this->rescheduleHold(
            service: $service,
            host: $host,
            original: $expiredOriginal,
            startsAt: '2026-07-28 12:00:00',
            idempotencyKey: 'expired-reschedule',
        );
        CarbonImmutable::setTestNow(
            CarbonImmutable::instance($expiredHold->expires_at)
                ->utc()
                ->addSecond(),
        );

        try {
            app(RescheduleAppointmentAction::class)->handle(
                new AppointmentRescheduleData($expiredHold->hold_id),
            );
            $this->fail('Expected an elapsed hold to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('expired before', $exception->getMessage());
        }

        $this->assertSame(BookingHold::STATUS_EXPIRED, $expiredHold->fresh()->status);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $expiredOriginal->fresh()->status);
    }

    public function test_ordinary_and_reschedule_hold_conversion_paths_cannot_be_interchanged(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $ordinaryHold = $this->ordinaryHold(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 09:00:00',
            idempotencyKey: 'ordinary-hold',
        );

        try {
            app(RescheduleAppointmentAction::class)->handle(
                new AppointmentRescheduleData($ordinaryHold->hold_id),
            );
            $this->fail('Expected an ordinary hold to be rejected by the reschedule action.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ordinary booking hold', $exception->getMessage());
        }

        $original = $this->confirmedAppointment($service, $host, '2026-07-28 10:00:00');
        $rescheduleHold = $this->rescheduleHold(
            service: $service,
            host: $host,
            original: $original,
            startsAt: '2026-07-28 11:00:00',
            idempotencyKey: 'reschedule-hold',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('RescheduleAppointmentAction');

        app(ConvertBookingHoldToAppointmentAction::class)->handle(
            $rescheduleHold->hold_id,
            new AppointmentBookingData(name: 'Wrong conversion path'),
        );
    }

    public function test_only_one_direct_replacement_can_be_created_for_an_appointment(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $original = $this->confirmedAppointment($service, $host, '2026-07-28 09:00:00');
        $firstHold = $this->rescheduleHold(
            service: $service,
            host: $host,
            original: $original,
            startsAt: '2026-07-28 10:00:00',
            idempotencyKey: 'first-replacement',
        );
        $secondHold = $this->rescheduleHold(
            service: $service,
            host: $host,
            original: $original,
            startsAt: '2026-07-28 11:00:00',
            idempotencyKey: 'second-replacement',
        );

        $replacement = app(RescheduleAppointmentAction::class)->handle(
            new AppointmentRescheduleData($firstHold->hold_id),
        );

        try {
            app(RescheduleAppointmentAction::class)->handle(
                new AppointmentRescheduleData($secondHold->hold_id),
            );
            $this->fail('Expected a second direct replacement to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cannot be rescheduled', $exception->getMessage());
        }

        $this->assertSame(1, Appointment::withTrashed()
            ->where('rescheduled_from_id', $original->id)
            ->count());
        $this->assertSame($replacement->id, $firstHold->fresh()->appointment_id);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $secondHold->fresh()->status);

        $this->expectException(QueryException::class);

        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'rescheduled_from_id' => $original->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => CarbonImmutable::parse('2026-07-28 12:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-28 13:00:00', 'UTC'),
        ]);
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
            'cancellation_notice_minutes' => 0,
            'reschedule_notice_minutes' => 0,
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$serviceAttributes,
        ]);
        $host = $this->assignHost($service);

        return [$service, $host];
    }

    private function assignHost(BookableService $service): SchedulingHost
    {
        $host = SchedulingHost::factory()->create([
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

    private function absoluteAvailability(
        BookableService $service,
        SchedulingHost $host,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-07-28 08:00:00', 'UTC'),
                CarbonImmutable::parse('2026-07-28 14:00:00', 'UTC'),
            )
            ->forServiceAndHost($service, $host)
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
    }

    private function confirmedAppointment(
        BookableService $service,
        SchedulingHost $host,
        string $startsAt,
    ): Appointment {
        $startsAt = CarbonImmutable::parse($startsAt, 'UTC');
        $contact = Contact::factory()->create();

        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'contact_id' => $contact->id,
            'primary_attendee_type' => $contact->getMorphClass(),
            'primary_attendee_id' => $contact->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes((int) $service->duration_minutes),
            'confirmed_at' => CarbonImmutable::parse('2026-07-27 10:00:00', 'UTC'),
        ]);

        AppointmentAttendee::factory()->create([
            'appointment_id' => $appointment->id,
            'attendee_type' => $contact->getMorphClass(),
            'attendee_id' => $contact->id,
            'contact_id' => $contact->id,
            'role' => 'primary',
            'status' => AppointmentAttendee::STATUS_ACCEPTED,
            'responded_at' => CarbonImmutable::parse('2026-07-27 10:00:00', 'UTC'),
        ]);

        return $appointment;
    }

    private function primaryAttendee(Appointment $appointment): AppointmentAttendee
    {
        return $appointment->attendees()
            ->where('role', 'primary')
            ->sole();
    }

    private function rescheduleHold(
        BookableService $service,
        SchedulingHost $host,
        Appointment $original,
        string $startsAt,
        string $idempotencyKey,
    ): BookingHold {
        $slot = $this->slotAt(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            rescheduleAppointment: $original,
        );
        $offer = app(IssueBookableSlotOfferAction::class)->handle(
            slot: $slot,
            rescheduleAppointment: $original,
        );

        return app(CreateBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: $idempotencyKey,
        );
    }

    private function ordinaryHold(
        BookableService $service,
        SchedulingHost $host,
        string $startsAt,
        string $idempotencyKey,
    ): BookingHold {
        $slot = $this->slotAt(
            service: $service,
            host: $host,
            startsAt: $startsAt,
        );
        $offer = app(IssueBookableSlotOfferAction::class)->handle($slot);

        return app(CreateBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: $idempotencyKey,
        );
    }

    private function slotAt(
        BookableService $service,
        SchedulingHost $host,
        string $startsAt,
        ?Appointment $rescheduleAppointment = null,
    ): BookableSlot {
        $slots = app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: CarbonImmutable::parse('2026-07-28 08:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-07-28 14:00:00', 'UTC'),
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
                rescheduleAppointment: $rescheduleAppointment,
            ),
        );

        foreach ($slots as $slot) {
            if ($slot->startsAt->format('Y-m-d H:i:s') === $startsAt) {
                return $slot;
            }
        }

        $this->fail("Expected slot [{$startsAt}] to be available.");
    }
}