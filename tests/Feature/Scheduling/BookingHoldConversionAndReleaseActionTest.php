<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Scheduling\Actions\ConvertBookingHoldToAppointmentAction;
use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Actions\ReleaseBookingHoldAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
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
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingHoldConversionAndReleaseActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-27 12:00:00', 'UTC'),
        );

        config()->set('scheduling.slot_offers.ttl_seconds', 300);
        config()->set('scheduling.booking_holds.ttl_seconds', 600);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_active_holds_reduce_availability_and_release_or_elapsed_expiration_restores_it(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $heldSlot = $this->slots($service, $host)[0];
        $hold = $this->holdSlot($heldSlot, 'availability-hold');

        $this->assertFalse($this->hasSlot($service, $host, $heldSlot));

        $released = app(ReleaseBookingHoldAction::class)->handle($hold->hold_id);
        $replayed = app(ReleaseBookingHoldAction::class)->handle($hold->hold_id);

        $this->assertSame(BookingHold::STATUS_RELEASED, $released->status);
        $this->assertSame($released->id, $replayed->id);
        $this->assertTrue($released->released_at->equalTo($replayed->released_at));
        $this->assertTrue($this->hasSlot($service, $host, $heldSlot));

        $replacement = $this->holdSlot(
            $this->matchingSlot($service, $host, $heldSlot),
            'elapsed-hold',
        );

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(601));

        $this->assertSame(BookingHold::STATUS_ACTIVE, $replacement->fresh()->status);
        $this->assertTrue($this->hasSlot($service, $host, $heldSlot));

        $expired = app(ReleaseBookingHoldAction::class)->handle($replacement->hold_id);

        $this->assertSame(BookingHold::STATUS_EXPIRED, $expired->status);
        $this->assertTrue($expired->isExpired());
    }

    public function test_conversion_creates_one_on_one_appointment_attendee_and_lifecycle_history_idempotently(): void
    {
        [$service, $host] = $this->hostedService([
            'name' => 'Initial Consultation',
            'capacity' => 2,
            'location_type' => 'virtual',
            'location_details' => [
                'provider' => 'internal',
                'instructions' => 'Meeting link follows separately.',
            ],
        ], [
            'capacity' => 2,
        ], [
            'capacity_override' => 2,
        ]);
        $this->absoluteAvailability($service, $host, capacity: 2);
        $slot = $this->slots($service, $host)[0];
        $hold = $this->holdSlot($slot, 'one-on-one-hold');
        $contact = Contact::factory()->create([
            'name' => 'Alex Example',
            'email' => 'alex@example.test',
            'phone' => '15555550100',
        ]);
        $booking = new AppointmentBookingData(
            contact: $contact,
            description: 'Discuss the requested service.',
            source: 'public_booking',
            appointmentMeta: [
                'request' => [
                    'locale' => 'en-US',
                ],
            ],
        );
        $action = app(ConvertBookingHoldToAppointmentAction::class);

        $appointment = $action->handle($hold->hold_id, $booking);
        $replayed = $action->handle(
            $hold->hold_id,
            new AppointmentBookingData(
                name: 'This retry must not replace the original snapshot.',
            ),
        );

        $this->assertSame($appointment->id, $replayed->id);
        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);
        $this->assertSame($service->id, $appointment->bookable_service_id);
        $this->assertSame($host->id, $appointment->scheduling_host_id);
        $this->assertSame($contact->id, $appointment->contact_id);
        $this->assertTrue($appointment->primaryAttendee->is($contact));
        $this->assertTrue($appointment->starts_at->equalTo($slot->startsAt));
        $this->assertTrue($appointment->ends_at->equalTo($slot->endsAt));
        $this->assertSame('Initial Consultation', $appointment->title);
        $this->assertSame('virtual', $appointment->location_type);
        $this->assertSame($service->location_details, $appointment->location_details);
        $this->assertSame('UTC', $appointment->timezone);
        $this->assertSame('public_booking', $appointment->source);
        $this->assertSame('en-US', data_get($appointment->meta, 'request.locale'));
        $this->assertSame($hold->hold_id, data_get($appointment->meta, 'booking.hold_id'));

        $attendee = $appointment->attendees()->sole();

        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $attendee->status);
        $this->assertSame('primary', $attendee->role);
        $this->assertSame($contact->id, $attendee->contact_id);
        $this->assertTrue($attendee->attendee->is($contact));
        $this->assertSame('Alex Example', $attendee->name);
        $this->assertSame('alex@example.test', $attendee->email);
        $this->assertSame('15555550100', $attendee->phone);
        $this->assertNotNull($attendee->responded_at);

        $event = $appointment->lifecycleEvents()->sole();

        $this->assertSame(AppointmentLifecycleEvent::EVENT_SCHEDULED, $event->event_key);
        $this->assertNull($event->from_status);
        $this->assertSame(Appointment::STATUS_SCHEDULED, $event->to_status);
        $this->assertSame('booking_hold_converted', $event->reason);
        $this->assertSame($hold->hold_id, data_get($event->context, 'booking_hold_id'));

        $convertedHold = $hold->fresh();

        $this->assertSame(BookingHold::STATUS_CONVERTED, $convertedHold->status);
        $this->assertSame($appointment->id, $convertedHold->appointment_id);
        $this->assertTrue($convertedHold->isConverted());
        $this->assertTrue($convertedHold->isTerminal());
        $this->assertNotNull($convertedHold->converted_at);
        $this->assertSame(1, $appointment->attendees()->count());
        $this->assertSame(1, $appointment->lifecycleEvents()->count());

        $remainingSlot = $this->matchingSlot($service, $host, $slot);

        $this->assertSame(2, $remainingSlot->capacity);
        $this->assertSame(1, $remainingSlot->remainingCapacity);
    }

    public function test_confirmation_required_services_create_pending_appointments(): void
    {
        [$service, $host] = $this->hostedService([
            'requires_confirmation' => true,
        ]);
        $this->absoluteAvailability($service, $host);
        $hold = $this->holdSlot($this->slots($service, $host)[0], 'pending-hold');
        $contact = Contact::factory()->create();

        $appointment = app(ConvertBookingHoldToAppointmentAction::class)->handle(
            $hold->hold_id,
            new AppointmentBookingData(contact: $contact),
        );

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->status);
        $this->assertNull($appointment->confirmed_at);

        $event = $appointment->lifecycleEvents()->sole();

        $this->assertSame(AppointmentLifecycleEvent::EVENT_CREATED, $event->event_key);
        $this->assertNull($event->from_status);
        $this->assertSame(Appointment::STATUS_PENDING, $event->to_status);
    }

    public function test_primary_subject_can_differ_from_the_associated_contact(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $hold = $this->holdSlot($this->slots($service, $host)[0], 'subject-hold');
        $owner = Contact::factory()->create([
            'name' => 'Rover Owner',
            'email' => 'owner@example.test',
        ]);
        $subject = ContactImportBatch::factory()->create([
            'name' => 'Rover',
        ]);

        $appointment = app(ConvertBookingHoldToAppointmentAction::class)->handle(
            $hold->hold_id,
            new AppointmentBookingData(
                contact: $owner,
                primaryAttendee: $subject,
            ),
        );
        $attendee = $appointment->attendees()->sole();

        $this->assertTrue($appointment->contact->is($owner));
        $this->assertTrue($appointment->primaryAttendee->is($subject));
        $this->assertTrue($attendee->contact->is($owner));
        $this->assertTrue($attendee->attendee->is($subject));
        $this->assertSame('Rover', $attendee->name);
        $this->assertSame('owner@example.test', $attendee->email);
    }

    public function test_released_and_expired_holds_cannot_convert_and_converted_holds_cannot_release(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $slots = $this->slots($service, $host);
        $contact = Contact::factory()->create();
        $convert = app(ConvertBookingHoldToAppointmentAction::class);
        $release = app(ReleaseBookingHoldAction::class);

        $released = $release->handle(
            $this->holdSlot($slots[0], 'released-hold')->hold_id,
        );

        try {
            $convert->handle(
                $released->hold_id,
                new AppointmentBookingData(contact: $contact),
            );
            $this->fail('Expected a released booking hold to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('released', $exception->getMessage());
        }

        $converted = $this->holdSlot($slots[1], 'converted-hold');
        $convert->handle(
            $converted->hold_id,
            new AppointmentBookingData(contact: $contact),
        );

        try {
            $release->handle($converted->hold_id);
            $this->fail('Expected a converted booking hold release to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('converted', $exception->getMessage());
        }

        config()->set('scheduling.booking_holds.ttl_seconds', 60);
        $expired = $this->holdSlot($slots[2], 'expired-hold');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));

        try {
            $convert->handle(
                $expired->hold_id,
                new AppointmentBookingData(contact: $contact),
            );
            $this->fail('Expected an elapsed booking hold to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('expired', $exception->getMessage());
        }

        $this->assertSame(
            BookingHold::STATUS_EXPIRED,
            $expired->fresh()->status,
        );
    }

    public function test_host_capacity_is_shared_across_services_with_active_holds(): void
    {
        $host = SchedulingHost::factory()->create([
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);
        $firstService = $this->service();
        $secondService = $this->service();

        foreach ([$firstService, $secondService] as $service) {
            BookableServiceHost::factory()->create([
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $host->id,
                'is_active' => true,
            ]);
            $this->absoluteAvailability($service, $host);
        }

        $firstSlot = $this->slots($firstService, $host)[0];
        $this->holdSlot($firstSlot, 'shared-host-hold');

        $this->assertFalse($this->hasSlot($secondService, $host, $firstSlot));
    }

    /**
     * @param array<string, mixed> $serviceAttributes
     * @param array<string, mixed> $hostAttributes
     * @param array<string, mixed> $assignmentAttributes
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedService(
        array $serviceAttributes = [],
        array $hostAttributes = [],
        array $assignmentAttributes = [],
    ): array {
        $service = $this->service($serviceAttributes);
        $host = SchedulingHost::factory()->create([
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$hostAttributes,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'is_active' => true,
            ...$assignmentAttributes,
        ]);

        return [$service, $host];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function service(array $attributes = []): BookableService
    {
        return BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$attributes,
        ]);
    }

    private function absoluteAvailability(
        BookableService $service,
        SchedulingHost $host,
        int $capacity = 1,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-07-28 09:00:00', 'UTC'),
                CarbonImmutable::parse('2026-07-28 12:00:00', 'UTC'),
            )
            ->forServiceAndHost($service, $host)
            ->create([
                'timezone' => 'UTC',
                'capacity' => $capacity,
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
                startsAt: CarbonImmutable::parse('2026-07-28 09:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-07-28 12:00:00', 'UTC'),
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

    private function hasSlot(
        BookableService $service,
        SchedulingHost $host,
        BookableSlot $expected,
    ): bool {
        foreach ($this->slots($service, $host) as $slot) {
            if ($slot->startsAt->equalTo($expected->startsAt)
                && $slot->endsAt->equalTo($expected->endsAt)
            ) {
                return true;
            }
        }

        return false;
    }

    private function matchingSlot(
        BookableService $service,
        SchedulingHost $host,
        BookableSlot $expected,
    ): BookableSlot {
        foreach ($this->slots($service, $host) as $slot) {
            if ($slot->startsAt->equalTo($expected->startsAt)
                && $slot->endsAt->equalTo($expected->endsAt)
            ) {
                return $slot;
            }
        }

        $this->fail('Expected the requested slot to be available.');
    }
}