<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\IssuePublicBookingSlotOfferAction;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicSchedulingOfferCodeUxTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_offer_prefill_stays_hidden_until_contact_details_and_then_prefills_the_code_field(): void
    {
        CarbonImmutable::setTestNow('2026-09-16 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');
        $service = $this->publicService();
        $this->availability($service);

        $this->get('https://schedule.test/services/'.$service->key.'?offer=FREEVA')
            ->assertOk()
            ->assertDontSee('data-scheduling-offer-code', false);

        $slotOffer = app(IssuePublicBookingSlotOfferAction::class)->handle(
            service: $service,
            startsAt: CarbonImmutable::parse('2026-09-17 09:00:00 UTC'),
            offerCodePrefill: 'FREEVA',
        );
        $hold = app(CreateBookingHoldAction::class)->handle(
            offerId: $slotOffer->offer_id,
            idempotencyKey: (string) Str::uuid(),
        );

        $this->get('https://schedule.test/book/'.$hold->hold_id)
            ->assertOk()
            ->assertSee('data-scheduling-offer-code', false)
            ->assertSee('name="offer_code"', false)
            ->assertSee('value="FREEVA"', false);
    }

    private function publicService(): BookableService
    {
        return BookableService::factory()->create([
            'key' => 'offer-code-consultation',
            'name' => 'Offer Code Consultation',
            'status' => BookableService::STATUS_ACTIVE,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 10,
            'timezone' => 'UTC',
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'in_person_arrangement' => null,
            'remote_method' => BookableService::REMOTE_METHOD_VIRTUAL_MEETING,
            'location_type' => BookableService::LOCATION_TYPE_VIRTUAL,
            'capacity' => 1,
            'is_public' => true,
        ]);
    }

    private function availability(BookableService $service): SchedulingAvailabilityWindow
    {
        return SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse('2026-09-17 09:00:00 UTC'),
                CarbonImmutable::parse('2026-09-17 10:00:00 UTC'),
            )
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
                'is_available' => true,
            ]);
    }

    private function registerPublicSurface(string $url): void
    {
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
            'reservation_rate_limit_per_minute' => 12,
            'hold_review_rate_limit_per_minute' => 60,
        ]);

        app()->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        Route::getRoutes()->refreshNameLookups();
    }
}