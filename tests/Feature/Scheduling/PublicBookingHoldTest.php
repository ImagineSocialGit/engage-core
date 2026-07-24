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

    public function test_public_time_selection_creates_one_opaque_hold_for_a_deterministic_hidden_host(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('consultation');
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

        $this->get('https://schedule.test/services/consultation?date=2026-07-23')
            ->assertOk()
            ->assertSee('name="starts_at"', false)
            ->assertSee('name="idempotency_key"', false)
            ->assertDontSee('Hidden First Host')
            ->assertDontSee('Hidden Second Host')
            ->assertDontSee('scheduling_host_id')
            ->assertDontSee('remaining_capacity')
            ->assertDontSee('offer_id');

        $response = $this->post(
            'https://schedule.test/services/consultation/reserve',
            [
                'starts_at' => '2026-07-23T09:00:00.000000Z',
                'idempotency_key' => (string) Str::uuid(),
            ],
        );

        $response->assertRedirect();

        $hold = BookingHold::query()->sole();

        $this->assertSame($service->id, $hold->bookable_service_id);
        $this->assertSame($firstHost->id, $hold->scheduling_host_id);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->status);
        $this->assertDatabaseCount('bookable_slot_offers', 1);
        $this->assertDatabaseCount('booking_holds', 1);

        $location = (string) $response->headers->get('Location');

        $this->assertSame(
            'https://schedule.test/book/'.$hold->hold_id,
            $location,
        );

        $this->get($location)
            ->assertOk()
            ->assertSee('Time reserved')
            ->assertSee('Consultation')
            ->assertSee('9:00 AM–10:00 AM')
            ->assertSee('data-remaining-seconds="600"', false)
            ->assertDontSee('Hidden First Host')
            ->assertDontSee('scheduling_host_id')
            ->assertDontSee('capacity')
            ->assertDontSee('slot_offer');

        $this->get('https://example.test'.$location)
            ->assertNotFound();
    }

    public function test_public_reservation_rejects_forged_or_unavailable_booking_state(): void
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

        $this->from($serviceUrl)
            ->post('https://booking.test/services/strategy-session/reserve', [
                'starts_at' => '2026-07-23T11:00:00.000000Z',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect($serviceUrl)
            ->assertSessionHasErrors('starts_at');

        $this->from($serviceUrl)
            ->post('https://booking.test/services/strategy-session/reserve', [
                'starts_at' => '2026-07-23T09:00:00.000000Z',
                'idempotency_key' => (string) Str::uuid(),
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

    public function test_private_services_cannot_create_public_holds(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://booking.test');

        BookableService::factory()->create([
            'key' => 'private-service',
            'is_public' => false,
            'timezone' => 'UTC',
        ]);

        $this->post('https://booking.test/services/private-service/reserve', [
            'starts_at' => '2026-07-23T09:00:00.000000Z',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();

        $this->assertDatabaseCount('bookable_slot_offers', 0);
        $this->assertDatabaseCount('booking_holds', 0);
    }

    public function test_reservation_rechecks_capacity_after_availability_was_displayed(): void
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

        $serviceUrl = 'https://schedule.test/services/capacity-race?date=2026-07-23';

        $this->get($serviceUrl)
            ->assertOk()
            ->assertSee('9:00 AM–10:00 AM');

        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'starts_at' => CarbonImmutable::parse('2026-07-23 09:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-23 10:00:00 UTC'),
            'timezone' => 'UTC',
        ]);

        $this->from($serviceUrl)
            ->post('https://schedule.test/services/capacity-race/reserve', [
                'starts_at' => '2026-07-23T09:00:00.000000Z',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect($serviceUrl)
            ->assertSessionHasErrors('starts_at');

        $this->assertDatabaseCount('bookable_slot_offers', 0);
        $this->assertDatabaseCount('booking_holds', 0);
    }

    public function test_public_reservation_retries_are_idempotent_and_conflicting_reuse_is_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('replay-service');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 11:00:00 UTC',
        );

        $key = (string) Str::uuid();
        $reserveUrl = 'https://schedule.test/services/replay-service/reserve';
        $serviceUrl = 'https://schedule.test/services/replay-service?date=2026-07-23';
        $payload = [
            'starts_at' => '2026-07-23T09:00:00.000000Z',
            'idempotency_key' => $key,
        ];

        $first = $this->post($reserveUrl, $payload);
        $second = $this->post($reserveUrl, $payload);

        $first->assertRedirect();
        $second->assertRedirect($first->headers->get('Location'));

        $this->assertDatabaseCount('bookable_slot_offers', 1);
        $this->assertDatabaseCount('booking_holds', 1);

        $this->from($serviceUrl)
            ->post($reserveUrl, [
                'starts_at' => '2026-07-23T10:00:00.000000Z',
                'idempotency_key' => $key,
            ])
            ->assertRedirect($serviceUrl)
            ->assertSessionHasErrors('starts_at');

        $this->assertDatabaseCount('bookable_slot_offers', 1);
        $this->assertDatabaseCount('booking_holds', 1);
    }

    public function test_hold_review_uses_absolute_expiration_without_extending_the_hold(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('expiring-service');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-07-23 09:00:00 UTC',
            endsAt: '2026-07-23 10:00:00 UTC',
        );

        $this->post('https://schedule.test/services/expiring-service/reserve', [
            'starts_at' => '2026-07-23T09:00:00.000000Z',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $hold = BookingHold::query()->sole();
        $originalExpiration = CarbonImmutable::instance($hold->expires_at)->utc();

        CarbonImmutable::setTestNow($originalExpiration->addSecond());

        $this->get('https://schedule.test/book/'.$hold->hold_id)
            ->assertOk()
            ->assertSee('Reservation expired')
            ->assertSee('This reservation has expired.')
            ->assertSee('data-remaining-seconds="0"', false);

        $hold->refresh();

        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->status);
        $this->assertTrue(
            CarbonImmutable::instance($hold->expires_at)
                ->utc()
                ->equalTo($originalExpiration),
        );
    }

    public function test_public_reservation_route_is_rate_limited(): void
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

        $payload = [
            'starts_at' => '2026-07-23T09:00:00.000000Z',
            'idempotency_key' => (string) Str::uuid(),
        ];
        $url = 'https://schedule.test/services/limited-service/reserve';

        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertStatus(429);
    }

    private function publicService(string $key): BookableService
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