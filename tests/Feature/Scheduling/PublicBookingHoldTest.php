<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBookingHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_public_time_selection_issues_a_non_blocking_offer_before_the_real_hold(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('consultation', [
            'location_type' => BookableService::LOCATION_TYPE_PHONE,
            'location_details' => [
                'label' => 'Phone appointment',
                'instructions' => 'We will call the number provided with the booking.',
            ],
        ]);
        $firstHost = SchedulingHost::factory()->create([
            'name' => 'Hidden First Host',
            'timezone' => 'UTC',
        ]);
        $secondHost = SchedulingHost::factory()->create([
            'name' => 'Hidden Second Host',
            'timezone' => 'UTC',
        ]);

        foreach ([$firstHost, $secondHost] as $host) {
            BookableServiceHost::factory()->create([
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $host->id,
            ]);
        }

        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 10:00:00 UTC',
        );

        $serviceUrl = 'https://schedule.test/services/consultation?date=2026-07-23';

        $this->get($serviceUrl)
            ->assertOk()
            ->assertSee('name="starts_at"', false)
            ->assertDontSee('Hidden First Host')
            ->assertDontSee('scheduling_host_id')
            ->assertDontSee('remaining_capacity')
            ->assertDontSee('source_window_ids');

        $offerResponse = $this->post(
            'https://schedule.test/services/consultation/offers',
            ['starts_at' => '2026-07-23T09:00:00.000000Z'],
        );

        $offerResponse->assertRedirect();

        $offer = BookableSlotOffer::query()->sole();

        $this->assertSame($service->id, $offer->bookable_service_id);
        $this->assertSame($firstHost->id, $offer->scheduling_host_id);
        $this->assertSame(BookableService::LOCATION_TYPE_PHONE, $offer->location_type);
        $this->assertEquals($service->location_details, $offer->location_details);
        $this->assertDatabaseCount('bookable_slot_offers', 1);
        $this->assertDatabaseCount('booking_holds', 0);

        $offerUrl = 'https://schedule.test/offers/'.$offer->offer_id;

        $this->assertSame($offerUrl, $offerResponse->headers->get('Location'));

        $this->get($offerUrl)
            ->assertOk()
            ->assertSee('name="idempotency_key"', false)
            ->assertSee('action="/offers/'.$offer->offer_id.'/hold"', false)
            ->assertDontSee('Hidden First Host')
            ->assertDontSee('scheduling_host_id')
            ->assertDontSee('remaining_capacity');

        $this->from($offerUrl)
            ->post($offerUrl.'/hold', [
                'idempotency_key' => (string) Str::uuid(),
                'verified' => true,
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors('verified');

        $this->assertDatabaseCount('booking_holds', 0);

        $holdResponse = $this->post($offerUrl.'/hold', [
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $holdResponse->assertRedirect();

        $hold = BookingHold::query()->sole();

        $this->assertSame($service->id, $hold->bookable_service_id);
        $this->assertSame($firstHost->id, $hold->scheduling_host_id);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->status);
        $this->assertNotNull($offer->refresh()->consumed_at);
        $this->assertDatabaseCount('booking_holds', 1);
        $this->assertSame(
            'https://schedule.test/book/'.$hold->hold_id,
            $holdResponse->headers->get('Location'),
        );
    }

    public function test_public_offer_rejects_forged_or_unavailable_booking_state(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://booking.test');

        $service = $this->publicService('strategy-session');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 10:00:00 UTC',
        );

        $serviceUrl = 'https://booking.test/services/strategy-session?date=2026-07-23';
        $offerUrl = 'https://booking.test/services/strategy-session/offers';

        $this->from($serviceUrl)
            ->post($offerUrl, [
                'starts_at' => '2026-07-23T11:00:00.000000Z',
            ])
            ->assertRedirect($serviceUrl)
            ->assertSessionHasErrors('starts_at');

        $this->from($serviceUrl)
            ->post($offerUrl, [
                'starts_at' => '2026-07-23T09:00:00.000000Z',
                'scheduling_host_id' => 999,
                'ends_at' => '2026-07-23T10:00:00.000000Z',
                'capacity' => 999,
                'offer_id' => (string) Str::uuid(),
            ])
            ->assertRedirect($serviceUrl)
            ->assertSessionHasErrors([
                'scheduling_host_id',
                'ends_at',
                'capacity',
                'offer_id',
            ]);

        $this->assertDatabaseCount('bookable_slot_offers', 0);
        $this->assertDatabaseCount('booking_holds', 0);
    }

    public function test_customer_site_is_prepared_before_authoritative_availability_and_is_bound_to_the_offer(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        config()->set('scheduling.travel.conservative_minutes', 45);
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('home-visit', [
            'slot_interval_minutes' => 60,
            'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            'location_details' => [
                'label' => 'Customer address',
                'instructions' => 'Meet the customer at the submitted address.',
            ],
        ]);
        $host = SchedulingHost::factory()->create([
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);

        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 11:00:00 UTC',
        );

        $priorLocation = app(SchedulingLocationSnapshotResolver::class)->normalizeAddress(
            type: BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            input: [
                'address_line_1' => '900 Prior Avenue',
                'address_line_2' => null,
                'city' => 'Denver',
                'region' => 'CO',
                'postal_code' => '80205',
                'country' => 'US',
            ],
        );

        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'starts_at' => CarbonImmutable::parse('2026-07-23 08:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-23 09:00:00 UTC'),
            'timezone' => 'UTC',
            ...$priorLocation->toColumns(),
        ]);

        $serviceUrl = 'https://schedule.test/services/home-visit?date=2026-07-23';
        $prepareUrl = 'https://schedule.test/services/home-visit/prepare';
        $offerUrl = 'https://schedule.test/services/home-visit/offers';

        $this->get($serviceUrl)
            ->assertOk()
            ->assertSee('name="address_line_1"', false)
            ->assertDontSee('name="starts_at"', false);

        $this->from($serviceUrl)
            ->post($offerUrl, [
                'starts_at' => '2026-07-23T09:00:00.000000Z',
            ])
            ->assertRedirect($serviceUrl)
            ->assertSessionHasErrors('address_line_1');

        $this->from($serviceUrl)
            ->post($prepareUrl, [
                ...$this->customerSiteAddress(),
                'latitude' => 39.7392,
            ])
            ->assertRedirect($serviceUrl)
            ->assertSessionHasErrors('latitude');

        $this->post($prepareUrl, $this->customerSiteAddress())
            ->assertRedirect('https://schedule.test/services/home-visit');

        $this->get($serviceUrl)
            ->assertOk()
            ->assertDontSee('value="2026-07-23T09:00:00.000000Z"', false)
            ->assertSee('value="2026-07-23T10:00:00.000000Z"', false)
            ->assertSee('name="starts_at"', false);

        $response = $this->post($offerUrl, [
            'starts_at' => '2026-07-23T10:00:00.000000Z',
        ])->assertRedirect();

        $offer = BookableSlotOffer::query()->sole();

        $this->assertSame(BookableService::LOCATION_TYPE_CUSTOMER_SITE, $offer->location_type);
        $this->assertSame('Customer address', data_get($offer->location_details, 'label'));
        $this->assertSame(
            'Meet the customer at the submitted address.',
            data_get($offer->location_details, 'instructions'),
        );
        $this->assertSame(
            '123 Main Street, Denver, CO 80202, US',
            data_get($offer->location_details, 'address.formatted_address'),
        );
        $this->assertDatabaseCount('booking_holds', 0);

        $this->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertSeeInOrder([
                '123 Main Street',
                'Denver, CO 80202',
            ])
            ->assertDontSee('123 Main Street, Denver, CO 80202, US');

        $this->post('https://schedule.test/offers/'.$offer->offer_id.'/hold', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $hold = BookingHold::query()->sole();

        $this->assertSame($offer->location_type, $hold->location_type);
        $this->assertEquals($offer->location_details, $hold->location_details);
    }

    public function test_public_range_service_issues_an_offer_for_the_complete_stay_before_holding_capacity(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = BookableService::factory()
            ->rangeDuration(
                defaultMinutes: 2880,
                minimumMinutes: 1440,
                maximumMinutes: 10080,
            )
            ->create([
                'key' => 'boarding-stay',
                'name' => 'Boarding Stay',
                'status' => BookableService::STATUS_ACTIVE,
                'slot_interval_minutes' => 60,
                'minimum_notice_minutes' => 0,
                'booking_horizon_days' => 10,
                'timezone' => 'UTC',
                'capacity' => 1,
                'is_public' => true,
            ]);

        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 15:00:00 UTC',
            endsAt: '2026-07-23 17:00:00 UTC',
        );
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-26 09:00:00 UTC',
            endsAt: '2026-07-26 11:00:00 UTC',
        );

        $serviceUrl = 'https://schedule.test/services/boarding-stay';

        $this->get($serviceUrl)
            ->assertOk()
            ->assertSee('name="range_starts_at"', false)
            ->assertSee('name="range_ends_at"', false)
            ->assertDontSee('name="starts_at"', false);

        $offerResponse = $this->post($serviceUrl.'/offers', [
            'range_starts_at' => '2026-07-23T15:00',
            'range_ends_at' => '2026-07-26T10:00',
        ]);

        $offerResponse
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $offer = BookableSlotOffer::query()->sole();

        $this->assertTrue($offer->starts_at->equalTo(
            CarbonImmutable::parse('2026-07-23 15:00:00 UTC'),
        ));
        $this->assertTrue($offer->ends_at->equalTo(
            CarbonImmutable::parse('2026-07-26 10:00:00 UTC'),
        ));
        $this->assertDatabaseCount('booking_holds', 0);

        $this->post('https://schedule.test/offers/'.$offer->offer_id.'/hold', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $hold = BookingHold::query()->sole();

        $this->assertTrue($hold->starts_at->equalTo($offer->starts_at));
        $this->assertTrue($hold->ends_at->equalTo($offer->ends_at));
    }

    public function test_private_services_cannot_issue_public_offers_or_holds(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://booking.test');

        BookableService::factory()->create([
            'key' => 'private-service',
            'is_public' => false,
            'timezone' => 'UTC',
        ]);

        $this->post('https://booking.test/services/private-service/offers', [
            'starts_at' => '2026-07-23T09:00:00.000000Z',
        ])->assertNotFound();

        $this->assertDatabaseCount('bookable_slot_offers', 0);
        $this->assertDatabaseCount('booking_holds', 0);
    }

    public function test_real_hold_rechecks_capacity_after_a_non_blocking_offer_was_selected(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('capacity-race');
        $host = SchedulingHost::factory()->create([
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);

        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 10:00:00 UTC',
        );

        $this->post('https://schedule.test/services/capacity-race/offers', [
            'starts_at' => '2026-07-23T09:00:00.000000Z',
        ])->assertRedirect();

        $offer = BookableSlotOffer::query()->sole();

        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'starts_at' => CarbonImmutable::parse('2026-07-23 09:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-23 10:00:00 UTC'),
            'timezone' => 'UTC',
        ]);

        $offerUrl = 'https://schedule.test/offers/'.$offer->offer_id;

        $this->from($offerUrl)
            ->post($offerUrl.'/hold', [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('booking_holds', 0);
        $this->assertNull($offer->refresh()->consumed_at);
    }

    public function test_public_hold_creation_is_idempotent_for_the_same_offer_and_replay_key(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('replay-service');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 10:00:00 UTC',
        );

        $this->post('https://schedule.test/services/replay-service/offers', [
            'starts_at' => '2026-07-23T09:00:00.000000Z',
        ])->assertRedirect();

        $offer = BookableSlotOffer::query()->sole();
        $key = (string) Str::uuid();
        $url = 'https://schedule.test/offers/'.$offer->offer_id.'/hold';

        $first = $this->post($url, ['idempotency_key' => $key]);
        $second = $this->post($url, ['idempotency_key' => $key]);

        $first->assertRedirect();
        $second->assertRedirect($first->headers->get('Location'));
        $this->assertDatabaseCount('booking_holds', 1);
    }

    public function test_offer_and_hold_expiration_are_authoritative_and_do_not_extend_on_review(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('expiring-service');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 10:00:00 UTC',
        );

        $this->post('https://schedule.test/services/expiring-service/offers', [
            'starts_at' => '2026-07-23T09:00:00.000000Z',
        ])->assertRedirect();

        $offer = BookableSlotOffer::query()->sole();
        $offerExpiration = CarbonImmutable::instance($offer->expires_at)->utc();

        CarbonImmutable::setTestNow($offerExpiration->addSecond());

        $offerUrl = 'https://schedule.test/offers/'.$offer->offer_id;

        $this->get($offerUrl)
            ->assertOk()
            ->assertDontSee('name="idempotency_key"', false);

        $this->from($offerUrl)
            ->post($offerUrl.'/hold', [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('booking_holds', 0);
        $this->assertTrue($offer->refresh()->expires_at->equalTo($offerExpiration));
    }

    public function test_public_offer_route_is_rate_limited(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface(
            url: 'https://schedule.test',
            reservationLimit: 2,
        );

        $service = $this->publicService('limited-service');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 10:00:00 UTC',
        );

        $payload = ['starts_at' => '2026-07-23T09:00:00.000000Z'];
        $url = 'https://schedule.test/services/limited-service/offers';

        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertStatus(429);
    }

    /**
     * @return array<string, string|null>
     */
    private function customerSiteAddress(): array
    {
        return [
            'address_line_1' => '  123   Main Street ',
            'address_line_2' => '',
            'city' => ' Denver ',
            'region' => ' CO ',
            'postal_code' => ' 80202 ',
            'country' => 'us',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function publicService(string $key, array $overrides = []): BookableService
    {
        return BookableService::factory()->create([
            'key' => $key,
            'name' => Str::headline($key),
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'booking_horizon_days' => 10,
            'timezone' => 'UTC',
            'capacity' => 1,
            'is_public' => true,
            ...$overrides,
        ]);
    }

    private function absoluteAvailability(
        BookableService $service,
        string $startsAt,
        string $endsAt,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse($startsAt),
                CarbonImmutable::parse($endsAt),
            )
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
    }

    private function registerPublicSurface(
        string $url,
        int $reservationLimit = 12,
    ): void {
        $parts = parse_url($url);
        $scheme = is_string($parts['scheme'] ?? null)
            ? strtolower($parts['scheme'])
            : null;
        $host = is_string($parts['host'] ?? null)
            ? strtolower($parts['host'])
            : null;

        $this->assertNotNull($scheme);
        $this->assertNotNull($host);

        config()->set('modules.enabled', [
            ...config('modules.enabled', []),
            'scheduling',
        ]);
        config()->set(
            'messaging.channel_availability.email.surfaces.scheduling_public_booking',
            false,
        );
        config()->set(
            'messaging.channel_availability.sms.surfaces.scheduling_public_booking',
            false,
        );
        config()->set('scheduling.public', [
            'enabled' => true,
            'url' => rtrim($url, '/'),
            'host' => $host,
            'scheme' => $scheme,
            'availability_max_days' => 31,
            'reservation_rate_limit_per_minute' => $reservationLimit,
            'hold_review_rate_limit_per_minute' => 60,
        ]);

        app()->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        Route::getRoutes()->refreshNameLookups();
    }
}