<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Actions\CreateAppointmentAction;
use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Contracts\TravelTimeResolver;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Data\TravelTimeEstimate;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\Availability\SchedulingTravelTimeResolver;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TravelAwareAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03 12:00:00 UTC');
        config()->set('scheduling.travel.maximum_minutes', 240);
        config()->set('scheduling.travel.conservative_minutes', 45);
        config()->set('scheduling.reschedule_suggestions.lookahead_days', 14);
        config()->set('scheduling.reschedule_suggestions.limit', 6);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_travel_time_resolution_defaults_conservatively_and_allows_an_optional_provider_override(): void
    {
        $first = $this->location('100 Main St', 'Denver', '80202');
        $same = $this->location('100 Main St', 'Denver', '80202');
        $other = $this->location('900 Market St', 'Denver', '80205');
        $providerDestination = $this->location('1200 Provider Way', 'Denver', '80206');
        $resolver = app(SchedulingTravelTimeResolver::class);

        $this->assertSame(0, $resolver->estimate($first, $same)->minutes);
        $this->assertSame('same_address', $resolver->estimate($first, $same)->source);
        $this->assertSame(45, $resolver->estimate($first, $other)->minutes);
        $this->assertSame('conservative_fallback', $resolver->estimate($first, $other)->source);

        $this->app->instance(TravelTimeResolver::class, new class implements TravelTimeResolver
        {
            public function estimate(
                SchedulingLocationSnapshot $origin,
                SchedulingLocationSnapshot $destination,
            ): TravelTimeEstimate {
                return new TravelTimeEstimate(
                    minutes: 12,
                    source: 'test_provider',
                );
            }
        });

        $estimate = $resolver->estimate($first, $providerDestination);

        $this->assertSame(12, $estimate->minutes);
        $this->assertSame('test_provider', $estimate->source);
    }

    public function test_adjacent_physical_appointments_require_travel_time_before_and_after_candidate_slots(): void
    {
        [$service, $host] = $this->hostedCustomerSiteService();
        $day = CarbonImmutable::parse('2026-08-05 00:00:00 UTC');
        $candidate = $this->location('500 Customer Rd', 'Denver', '80203');

        $this->availability(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 0),
            endsAt: $day->setTime(14, 0),
        );

        $this->appointment(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 0),
            location: $this->location('100 Prior Ave', 'Denver', '80202'),
        );
        $this->appointment(
            service: $service,
            host: $host,
            startsAt: $day->setTime(13, 0),
            location: $this->location('900 Next Ave', 'Denver', '80205'),
        );

        $slots = $this->find(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 0),
            endsAt: $day->setTime(14, 0),
            location: $candidate,
        );

        $this->assertEquals(
            ['10:45', '11:00', '11:15'],
            array_map(
                static fn (BookableSlot $slot): string => $slot->startsAt->format('H:i'),
                $slots,
            ),
        );
        $this->assertSame(45, $slots[0]->travelMinutesBefore);
        $this->assertSame(45, $slots[0]->travelMinutesAfter);
    }

    public function test_same_physical_address_requires_no_extra_travel_gap(): void
    {
        [$service, $host] = $this->hostedCustomerSiteService([
            'slot_interval_minutes' => 60,
        ]);
        $day = CarbonImmutable::parse('2026-08-05 00:00:00 UTC');
        $location = $this->location('100 Main St', 'Denver', '80202');

        $this->availability(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 0),
            endsAt: $day->setTime(12, 0),
        );
        $this->appointment(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 0),
            location: $location,
        );

        $slots = $this->find(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 0),
            endsAt: $day->setTime(12, 0),
            location: $location,
        );

        $this->assertEquals(
            ['10:00', '11:00'],
            array_map(
                static fn (BookableSlot $slot): string => $slot->startsAt->format('H:i'),
                $slots,
            ),
        );
        $this->assertSame(0, $slots[0]->travelMinutesBefore);
    }

    public function test_active_physical_holds_participate_in_travel_safety(): void
    {
        [$service, $host] = $this->hostedCustomerSiteService();
        $day = CarbonImmutable::parse('2026-08-05 00:00:00 UTC');
        $priorLocation = $this->location('100 Prior Ave', 'Denver', '80202');
        $candidateLocation = $this->location('500 Customer Rd', 'Denver', '80203');

        $this->availability(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 0),
            endsAt: $day->setTime(12, 0),
        );

        $priorSlot = $this->slotAt(
            slots: $this->find(
                service: $service,
                host: $host,
                startsAt: $day->setTime(9, 0),
                endsAt: $day->setTime(12, 0),
                location: $priorLocation,
            ),
            startsAt: $day->setTime(9, 0),
        );
        $offer = app(IssueBookableSlotOfferAction::class)->handle($priorSlot);
        app(CreateBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: (string) Str::uuid(),
            location: $priorLocation,
        );

        $slots = $this->find(
            service: $service,
            host: $host,
            startsAt: $day->setTime(10, 0),
            endsAt: $day->setTime(12, 0),
            location: $candidateLocation,
        );

        $this->assertEquals(
            ['10:45', '11:00'],
            array_map(
                static fn (BookableSlot $slot): string => $slot->startsAt->format('H:i'),
                $slots,
            ),
        );
    }

    public function test_booking_hold_creation_revalidates_travel_after_an_offer_was_issued(): void
    {
        [$service, $host] = $this->hostedCustomerSiteService([
            'slot_interval_minutes' => 60,
        ]);
        $day = CarbonImmutable::parse('2026-08-05 00:00:00 UTC');
        $candidateLocation = $this->location('500 Customer Rd', 'Denver', '80203');

        $this->availability(
            service: $service,
            host: $host,
            startsAt: $day->setTime(10, 0),
            endsAt: $day->setTime(11, 0),
        );
        $slot = $this->slotAt(
            slots: $this->find(
                service: $service,
                host: $host,
                startsAt: $day->setTime(10, 0),
                endsAt: $day->setTime(11, 0),
                location: $candidateLocation,
            ),
            startsAt: $day->setTime(10, 0),
        );
        $offer = app(IssueBookableSlotOfferAction::class)->handle($slot);

        $this->appointment(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 30),
            location: $this->location('100 Prior Ave', 'Denver', '80202'),
            durationMinutes: 30,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no longer available');

        app(CreateBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: (string) Str::uuid(),
            location: $candidateLocation,
        );
    }

    public function test_direct_appointment_creation_revalidates_travel_inside_the_creation_transaction(): void
    {
        [$service, $host] = $this->hostedCustomerSiteService([
            'slot_interval_minutes' => 60,
        ]);
        $day = CarbonImmutable::parse('2026-08-05 00:00:00 UTC');
        $candidateLocation = $this->location('500 Customer Rd', 'Denver', '80203');

        $this->availability(
            service: $service,
            host: $host,
            startsAt: $day->setTime(10, 0),
            endsAt: $day->setTime(11, 0),
        );
        $this->appointment(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 30),
            location: $this->location('100 Prior Ave', 'Denver', '80202'),
            durationMinutes: 30,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no longer available');

        app(CreateAppointmentAction::class)->handle(new AppointmentCreationData(
            service: $service,
            startsAt: $day->setTime(10, 0),
            host: $host,
            idempotencyKey: (string) Str::uuid(),
            booking: new AppointmentBookingData(
                name: 'Travel Test Customer',
                email: 'travel-test@example.test',
                location: $candidateLocation,
                source: 'crm',
            ),
        ));
    }

    public function test_admin_reschedule_suggestions_preserve_the_original_booking_location_and_prefer_nearby_times(): void
    {
        [$service, $host] = $this->hostedCustomerSiteService();
        $day = CarbonImmutable::parse('2026-08-05 00:00:00 UTC');
        $location = $this->location('500 Customer Rd', 'Denver', '80203');
        $original = $this->appointment(
            service: $service,
            host: $host,
            startsAt: $day->setTime(11, 0),
            location: $location,
        );

        $this->availability(
            service: $service,
            host: $host,
            startsAt: $day->setTime(9, 0),
            endsAt: $day->setTime(14, 0),
        );

        $suggestions = app(SchedulingReadService::class)->rescheduleSuggestions(
            appointment: $original,
            host: $host,
            evaluatedAt: CarbonImmutable::now('UTC'),
            limit: 4,
        );

        $this->assertCount(4, $suggestions);
        $this->assertFalse($suggestions[0]->startsAt->equalTo($original->starts_at));
        $this->assertSame('10:45', $suggestions[0]->startsAt->format('H:i'));
        $this->assertSame('11:15', $suggestions[1]->startsAt->format('H:i'));
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedCustomerSiteService(array $attributes = []): array
    {
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 15,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 1,
            'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            'location_details' => [
                'label' => 'Customer site',
                'instructions' => 'Meet at the submitted address.',
            ],
            ...$attributes,
        ]);
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

        return [$service, $host];
    }

    private function location(
        string $addressLine1,
        string $city,
        string $postalCode,
    ): SchedulingLocationSnapshot {
        return app(SchedulingLocationSnapshotResolver::class)->normalizeAddress(
            type: BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            input: [
                'address_line_1' => $addressLine1,
                'address_line_2' => null,
                'city' => $city,
                'region' => 'CO',
                'postal_code' => $postalCode,
                'country' => 'US',
            ],
        );
    }

    private function appointment(
        BookableService $service,
        SchedulingHost $host,
        CarbonImmutable $startsAt,
        SchedulingLocationSnapshot $location,
        int $durationMinutes = 60,
    ): Appointment {
        return Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($durationMinutes),
            'location_type' => $location->type,
            'location_details' => $location->details,
        ]);
    }

    private function availability(
        BookableService $service,
        SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->absolute($startsAt, $endsAt)
            ->forServiceAndHost($service, $host)
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
    }

    /**
     * @return array<int, BookableSlot>
     */
    private function find(
        BookableService $service,
        SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        SchedulingLocationSnapshot $location,
    ): array {
        return app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: $startsAt,
                endsAt: $endsAt,
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
                location: $location,
            ),
        );
    }

    /**
     * @param array<int, BookableSlot> $slots
     */
    private function slotAt(array $slots, CarbonImmutable $startsAt): BookableSlot
    {
        foreach ($slots as $slot) {
            if ($slot->startsAt->equalTo($startsAt)) {
                return $slot;
            }
        }

        $this->fail('Expected the requested travel-aware slot to be available.');
    }
}