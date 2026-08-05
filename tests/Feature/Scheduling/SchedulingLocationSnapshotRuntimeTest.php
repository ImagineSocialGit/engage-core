<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\ConvertBookingHoldToAppointmentAction;
use App\Modules\Scheduling\Actions\CreateAppointmentAction;
use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SchedulingLocationSnapshotRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-04 12:00:00', 'UTC'),
        );

        config()->set('scheduling.slot_offers.ttl_seconds', 300);
        config()->set('scheduling.booking_holds.ttl_seconds', 600);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_customer_site_hold_snapshot_is_normalized_and_remains_authoritative_through_conversion(): void
    {
        [$service, $host] = $this->hostedService([
            'name' => 'On-site Consultation',
            'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            'location_details' => [
                'label' => 'Customer service address',
                'instructions' => 'Meet the customer at the supplied address.',
            ],
        ]);
        $this->absoluteAvailability($service, $host);

        $requested = $this->customerSiteSnapshot(
            addressLine1: '  123   Main Street  ',
            city: '  Denver ',
            postalCode: ' 80202 ',
        );
        $slot = $this->slots($service, $host)[0];
        $offer = app(IssueBookableSlotOfferAction::class)->handle($slot);
        $action = app(CreateBookingHoldAction::class);
        $hold = $action->handle(
            offerId: $offer->offer_id,
            idempotencyKey: 'customer-site-hold-1',
            location: $requested,
        );

        $this->assertSame(
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            $hold->location_type,
        );
        $this->assertSame(
            'Customer service address',
            data_get($hold->location_details, 'label'),
        );
        $this->assertSame(
            '123 Main Street, Denver, CO 80202, US',
            data_get($hold->location_details, 'address.formatted_address'),
        );
        $this->assertNull(
            data_get($hold->location_details, 'address.latitude'),
        );

        $replayed = $action->handle(
            offerId: $offer->offer_id,
            idempotencyKey: 'customer-site-hold-1',
            location: $requested,
        );

        $this->assertSame($hold->id, $replayed->id);

        $service->forceFill([
            'location_details' => [
                'label' => 'Changed after the hold',
                'instructions' => 'These later values must not rewrite the hold.',
            ],
        ])->save();

        $contact = Contact::factory()->create([
            'name' => 'Alex Example',
            'email' => 'alex@example.test',
        ]);
        $appointment = app(ConvertBookingHoldToAppointmentAction::class)->handle(
            holdId: $hold->hold_id,
            booking: new AppointmentBookingData(contact: $contact),
        );

        $this->assertSame($hold->location_type, $appointment->location_type);
        $this->assertEquals($hold->location_details, $appointment->location_details);
        $this->assertSame(
            'Customer service address',
            data_get($appointment->location_details, 'label'),
        );

        $different = $this->customerSiteSnapshot(
            addressLine1: '500 Other Avenue',
            city: 'Denver',
            postalCode: '80203',
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The booking hold idempotency key was already used with another location snapshot.',
        );

        $action->handle(
            offerId: $offer->offer_id,
            idempotencyKey: 'customer-site-hold-1',
            location: $different,
        );
    }

    public function test_direct_customer_site_creation_requires_and_persists_a_normalized_snapshot(): void
    {
        [$service, $host] = $this->hostedService([
            'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            'location_details' => [
                'instructions' => 'Use the customer entrance.',
            ],
        ]);
        $startsAt = CarbonImmutable::parse('2026-08-05 09:00:00', 'UTC');
        $this->absoluteAvailability(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            endsAt: $startsAt->addHour(),
        );
        $contact = Contact::factory()->create();
        $action = app(CreateAppointmentAction::class);

        try {
            $action->handle(new AppointmentCreationData(
                service: $service,
                host: $host,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(
                    contact: $contact,
                    source: 'crm',
                ),
                idempotencyKey: 'customer-site-direct-missing-location',
            ));

            $this->fail('Customer-site direct creation should require a location snapshot.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Customer-site services require a normalized service address before a booking commitment can be created.',
                $exception->getMessage(),
            );
        }

        $location = $this->customerSiteSnapshot(
            addressLine1: '900 Market Street',
            city: 'Denver',
            postalCode: '80204',
        );
        $data = new AppointmentCreationData(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(
                contact: $contact,
                source: 'crm',
                location: $location,
            ),
            idempotencyKey: 'customer-site-direct-with-location',
        );

        $appointment = $action->handle($data);
        $replayed = $action->handle($data);

        $this->assertSame($appointment->id, $replayed->id);
        $this->assertSame(
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            $appointment->location_type,
        );
        $this->assertSame(
            '900 Market Street, Denver, CO 80204, US',
            data_get($appointment->location_details, 'address.formatted_address'),
        );
        $this->assertSame(
            'Use the customer entrance.',
            data_get($appointment->location_details, 'instructions'),
        );

        $different = $this->customerSiteSnapshot(
            addressLine1: '901 Market Street',
            city: 'Denver',
            postalCode: '80204',
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The appointment idempotency key was already used for another creation request.',
        );

        $action->handle(new AppointmentCreationData(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(
                contact: $contact,
                source: 'crm',
                location: $different,
            ),
            idempotencyKey: 'customer-site-direct-with-location',
        ));
    }

    public function test_fixed_and_virtual_service_locations_are_server_owned_snapshots(): void
    {
        $fixedAddress = app(SchedulingLocationSnapshotResolver::class)->normalizeAddress(
            type: BookableService::LOCATION_TYPE_FIXED,
            input: [
                'address_line_1' => '50 Office Plaza',
                'address_line_2' => null,
                'city' => 'Denver',
                'region' => 'CO',
                'postal_code' => '80205',
                'country' => 'US',
            ],
            label: 'Main office',
        );

        [$fixedService, $host] = $this->hostedService([
            'location_type' => BookableService::LOCATION_TYPE_FIXED,
            'location_details' => $fixedAddress->details,
        ]);
        $this->absoluteAvailability($fixedService, $host);
        $fixedSlot = $this->slots($fixedService, $host)[0];
        $fixedOffer = app(IssueBookableSlotOfferAction::class)->handle($fixedSlot);
        $fixedHold = app(CreateBookingHoldAction::class)->handle(
            offerId: $fixedOffer->offer_id,
            idempotencyKey: 'fixed-location-hold',
        );

        $this->assertSame(
            '50 Office Plaza, Denver, CO 80205, US',
            data_get($fixedHold->location_details, 'address.formatted_address'),
        );

        [$virtualService, $virtualHost] = $this->hostedService([
            'location_type' => BookableService::LOCATION_TYPE_VIRTUAL,
            'location_details' => [
                'provider' => 'internal',
                'instructions' => 'Meeting link follows separately.',
            ],
        ]);
        $this->absoluteAvailability($virtualService, $virtualHost);
        $virtualSlot = $this->slots($virtualService, $virtualHost)[0];
        $virtualOffer = app(IssueBookableSlotOfferAction::class)->handle($virtualSlot);
        $virtualHold = app(CreateBookingHoldAction::class)->handle(
            offerId: $virtualOffer->offer_id,
            idempotencyKey: 'virtual-location-hold',
        );

        $this->assertEquals(
            $virtualService->location_details,
            $virtualHold->location_details,
        );

        $override = SchedulingLocationSnapshot::canonical(
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            $fixedAddress->details,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Only customer-site services accept a booking-specific location snapshot.',
        );

        app(SchedulingLocationSnapshotResolver::class)->forCommitment(
            service: $virtualService,
            requested: $override,
        );
    }

    private function customerSiteSnapshot(
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
                'country' => 'us',
            ],
        );
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
        ?CarbonImmutable $startsAt = null,
        ?CarbonImmutable $endsAt = null,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->absolute(
                $startsAt ?? CarbonImmutable::parse('2026-08-05 09:00:00', 'UTC'),
                $endsAt ?? CarbonImmutable::parse('2026-08-05 12:00:00', 'UTC'),
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
                startsAt: CarbonImmutable::parse('2026-08-05 09:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-08-05 12:00:00', 'UTC'),
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
            ),
        );
    }
}