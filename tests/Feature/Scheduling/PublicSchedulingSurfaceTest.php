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

        $response = $this->get('https://booking.test/');

        $response
            ->assertOk()
            ->assertSeeInOrder(['First Service', 'Second Service'])
            ->assertDontSee('Private Service')
            ->assertDontSee('Inactive Service');
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
            ->assertSee('9:00 AM–10:00 AM')
            ->assertSee('10:00 AM–11:00 AM')
            ->assertSee('11:00 AM–12:00 PM')
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
            ->assertSee('9:00 AM–10:00 AM', false);

        $this->assertSame(
            1,
            substr_count($response->getContent(), '9:00 AM–10:00 AM'),
        );
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
            ->assertSee('No public services are available.');

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
            ->assertSee('No appointment times are currently available for this date.');
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