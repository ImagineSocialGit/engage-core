<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Support\Clients\ClientEnvironmentLoader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicSchedulingSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_scheduling_public_url_is_selected_client_environment_configuration(): void
    {
        $this->assertContains(
            'SCHEDULING_APP_URL',
            ClientEnvironmentLoader::clientOwnedKeys(),
        );
    }

    public function test_public_routes_are_registered_only_for_a_configured_host(): void
    {
        $this->registerPublicSurface('https://schedule.test');

        $this->assertTrue(Route::has('scheduling.public.index'));
        $this->assertTrue(Route::has('scheduling.public.services.show'));
        $this->assertTrue(Route::has('scheduling.public.services.prepare'));
        $this->assertTrue(Route::has('scheduling.public.services.offers.store'));
        $this->assertTrue(Route::has('scheduling.public.offers.show'));
        $this->assertTrue(Route::has('scheduling.public.offers.verification.issue'));
        $this->assertTrue(Route::has('scheduling.public.offers.verification.verify'));
        $this->assertTrue(Route::has('scheduling.public.offers.verification.resend'));
        $this->assertTrue(Route::has('scheduling.public.offers.hold'));
        $this->assertTrue(Route::has('scheduling.public.holds.show'));
        $this->assertTrue(Route::has('scheduling.public.holds.complete'));
        $this->assertFalse(Route::has('scheduling.public.services.reserve'));

        $service = BookableService::factory()->create([
            'key' => 'consultation',
            'name' => 'Consultation',
            'is_public' => true,
        ]);

        $this->get('https://schedule.test/')
            ->assertOk()
            ->assertSee('Consultation');

        $this->get("https://schedule.test/services/{$service->key}")
            ->assertOk();

        $this->get('https://crm.test/')
            ->assertNotFound();

        $this->get("https://example.test/services/{$service->key}")
            ->assertNotFound();
    }

    public function test_catalog_lists_only_active_public_services_in_configured_order(): void
    {
        $this->registerPublicSurface('https://booking.test');

        BookableService::factory()->create([
            'key' => 'second-service',
            'name' => 'Second Service',
            'is_public' => true,
            'sort_order' => 20,
        ]);
        BookableService::factory()->create([
            'key' => 'first-service',
            'name' => 'First Service',
            'is_public' => true,
            'sort_order' => 10,
        ]);
        BookableService::factory()->create([
            'key' => 'private-service',
            'name' => 'Private Service',
            'is_public' => false,
        ]);
        BookableService::factory()->inactive()->create([
            'key' => 'inactive-service',
            'name' => 'Inactive Service',
        ]);
        BookableService::factory()->rangeDuration()->create([
            'key' => 'range-service',
            'name' => 'Range Service',
            'is_public' => true,
            'sort_order' => 30,
        ]);

        $response = $this->get('https://booking.test/');

        $response
            ->assertOk()
            ->assertSeeInOrder(['First Service', 'Second Service', 'Range Service'])
            ->assertDontSee('Private Service')
            ->assertDontSee('Inactive Service');

        $this->get('https://booking.test/services/range-service')
            ->assertOk()
            ->assertSee('name="range_starts_at"', false)
            ->assertSee('name="range_ends_at"', false);
    }

    public function test_service_page_renders_bounded_local_availability_without_internal_booking_details(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://appointments.test');

        $service = BookableService::factory()->create([
            'key' => 'strategy-session',
            'name' => 'Strategy Session',
            'description' => 'A focused appointment.',
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'booking_horizon_days' => 10,
            'timezone' => 'America/Chicago',
            'capacity' => 2,
            'is_public' => true,
        ]);
        $host = SchedulingHost::factory()->create([
            'name' => 'Private Host Identity',
            'timezone' => 'America/Chicago',
            'capacity' => 2,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'capacity_override' => 2,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse('2026-07-23 14:00:00 UTC'),
                CarbonImmutable::parse('2026-07-23 17:00:00 UTC'),
            )
            ->create([
                'timezone' => 'America/Chicago',
                'capacity' => 2,
            ]);

        $response = $this->get(
            'https://appointments.test/services/strategy-session?date=2026-07-23',
        );

        $response
            ->assertOk()
            ->assertSee('Strategy Session')
            ->assertSee('America/Chicago')
            ->assertSee('data-public-surface', false)
            ->assertSee('data-public-surface-header', false)
            ->assertSee('data-public-surface-brand', false)
            ->assertSee('data-public-surface-label', false)
            ->assertSee('data-scheduling-public-booking', false)
            ->assertSee('data-time-selector', false)
            ->assertSee('data-day-period-tab="morning"', false)
            ->assertSee('data-day-period-panel="morning"', false)
            ->assertSee('data-time-option-input', false)
            ->assertSee('data-time-option', false)
            ->assertSee('data-start-label="9:00 AM"', false)
            ->assertSee('data-full-label="9:00–10:00 AM"', false)
            ->assertSee('data-time-continue', false)
            ->assertDontSee('<details class="time-option"', false)
            ->assertDontSee('Private Host Identity')
            ->assertDontSee('scheduling_host_id')
            ->assertDontSee('remaining_capacity')
            ->assertDontSee('source_window_ids');
    }

    public function test_duplicate_host_specific_slots_are_presented_once(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = BookableService::factory()->create([
            'key' => 'multi-host-service',
            'name' => 'Multi Host Service',
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'timezone' => 'UTC',
            'is_public' => true,
        ]);

        foreach (['Host One', 'Host Two'] as $hostName) {
            $host = SchedulingHost::factory()->create([
                'name' => $hostName,
                'timezone' => 'UTC',
            ]);

            BookableServiceHost::factory()->create([
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $host->id,
            ]);
        }

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse('2026-07-23 09:00:00 UTC'),
                CarbonImmutable::parse('2026-07-23 10:00:00 UTC'),
            )
            ->create(['timezone' => 'UTC']);

        $response = $this->get(
            'https://schedule.test/services/multi-host-service?date=2026-07-23',
        );

        $response
            ->assertOk()
            ->assertSee('9:00–10:00 AM', false);

        $this->assertSame(
            1,
            substr_count($response->getContent(), '9:00–10:00 AM'),
        );
    }

    public function test_public_surface_uses_plain_language_and_layered_client_presentation_overrides(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        config()->set('scheduling.public.presentation.brand_name', 'Example Client');
        config()->set('scheduling.public.presentation.primary_color', null);
        config()->set('public_surfaces.theme.colors.primary', '#123456');
        config()->set('public_surfaces.theme.layout.header', 'fixture-public-header');
        config()->set('public_surfaces.theme.components.card.base', 'fixture-public-card');
        config()->set('public_surfaces.theme.components.button.base', 'fixture-public-button');
        config()->set(
            'scheduling.public.presentation.style.catalog_title',
            'fixture-scheduling-catalog-title',
        );
        config()->set(
            'scheduling.public.presentation.style.service_title',
            'fixture-scheduling-service-title',
        );

        $service = BookableService::factory()->create([
            'key' => 'plain-language',
            'name' => 'Plain Language',
            'timezone' => 'UTC',
            'is_public' => true,
        ]);

        $response = $this->get('https://schedule.test/');

        $response
            ->assertOk()
            ->assertSee('Example Client')
            ->assertSee('--public-primary: #123456', false)
            ->assertSee('fixture-public-header', false)
            ->assertSee('fixture-scheduling-catalog-title', false)
            ->assertSee('data-public-surface', false)
            ->assertSee('data-scheduling-public-style-contract="1"', false)
            ->assertSee('data-report-service-selected', false)
            ->assertDontSee('Step 1');

        $this->get('https://schedule.test/services/'.$service->key)
            ->assertOk()
            ->assertSee('fixture-public-card', false)
            ->assertSee('fixture-public-button', false)
            ->assertSee('fixture-scheduling-service-title', false)
            ->assertSee('id="scheduling-public-booking-config"', false)
            ->assertDontSee('opaque offer')
            ->assertDontSee('authoritative availability');
    }

    public function test_public_surface_renders_shared_generated_client_logo_descriptor(): void
    {
        $this->registerPublicSurface('https://schedule.test');

        config()->set('filesystems.disks.spaces.url', 'https://cdn.example.test');
        config()->set('client.key', 'example-client');
        config()->set('public_surfaces.theme.brand.logo', [
            'path' => 'brand/logo',
            'sizes' => [320, 640],
            'placeholder' => 'brand/logo/placeholder.webp',
        ]);

        $response = $this->get('https://schedule.test/');

        $response
            ->assertOk()
            ->assertSee(
                'https://cdn.example.test/example-client/images/brand/logo/640.webp',
                false,
            )
            ->assertSee(
                'https://cdn.example.test/example-client/images/brand/logo/320.avif 320w',
                false,
            );
    }

    public function test_fixed_location_availability_separates_address_and_preparation_details(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        BookableService::factory()->create([
            'key' => 'office-consultation',
            'name' => 'Office Consultation',
            'timezone' => 'UTC',
            'location_type' => BookableService::LOCATION_TYPE_FIXED,
            'location_details' => [
                'label' => 'Main Office',
                'instructions' => 'Bring the documents requested by the team.',
                'address' => [
                    'address_line_1' => '123 Main Street',
                    'address_line_2' => null,
                    'city' => 'Nashville',
                    'region' => 'TN',
                    'postal_code' => '37201',
                    'country' => 'US',
                    'formatted_address' => '123 Main Street, Nashville, TN 37201, US',
                ],
            ],
            'is_public' => true,
        ]);

        $response = $this->get('https://schedule.test/services/office-consultation');

        $response
            ->assertOk()
            ->assertSee('data-booking-location', false)
            ->assertSee('data-booking-location-address', false)
            ->assertSee('data-booking-preparation', false)
            ->assertSeeInOrder([
                '123 Main Street',
                'Nashville, TN 37201',
            ]);
    }

    public function test_private_unknown_and_out_of_range_service_requests_are_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://booking.test');

        BookableService::factory()->create([
            'key' => 'private-service',
            'is_public' => false,
        ]);
        BookableService::factory()->create([
            'key' => 'public-service',
            'booking_horizon_days' => 5,
            'timezone' => 'UTC',
            'is_public' => true,
        ]);

        $this->get('https://booking.test/services/private-service')
            ->assertNotFound();

        $this->get('https://booking.test/services/missing-service')
            ->assertNotFound();

        $this->from('https://booking.test/services/public-service')
            ->get('https://booking.test/services/public-service?date=2026-08-20')
            ->assertRedirect('https://booking.test/services/public-service')
            ->assertSessionHasErrors('date');
    }

    public function test_empty_catalog_and_empty_date_have_public_empty_states(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $this->get('https://schedule.test/')
            ->assertOk()
            ->assertSee('data-booking-empty-state="services"', false);

        BookableService::factory()->create([
            'key' => 'unavailable-service',
            'name' => 'Unavailable Service',
            'timezone' => 'UTC',
            'is_public' => true,
        ]);

        $this->get(
            'https://schedule.test/services/unavailable-service?date=2026-07-23',
        )
            ->assertOk()
            ->assertSee('data-booking-empty-state="times"', false);
    }

    public function test_unconfigured_or_invalid_public_configuration_registers_no_routes(): void
    {
        $this->assertFalse(Route::has('scheduling.public.index'));

        config()->set('modules.enabled', [
            ...config('modules.enabled', []),
            'scheduling',
        ]);
        config()->set('scheduling.public', [
            'enabled' => false,
            'url' => null,
            'host' => null,
            'scheme' => null,
            'availability_max_days' => 31,
        ]);

        app()->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        $this->assertFalse(Route::has('scheduling.public.index'));
        $this->assertFalse(Route::has('scheduling.public.services.show'));
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
        config()->set('scheduling.public', [
            'enabled' => true,
            'url' => rtrim($url, '/'),
            'host' => $host,
            'scheme' => $scheme,
            'availability_max_days' => 31,
        ]);

        app()->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        Route::getRoutes()->refreshNameLookups();
    }
}