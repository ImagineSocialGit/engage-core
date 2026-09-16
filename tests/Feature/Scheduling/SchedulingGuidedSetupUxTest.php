<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingBookingOffer;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Services\SchedulingSetupProgress;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingGuidedSetupUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-16 16:00:00 UTC');
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
            'tasks',
        ])));

        if (! $this->app->getProvider(SchedulingModuleServiceProvider::class)) {
            $this->app->register(SchedulingModuleServiceProvider::class);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_completed_current_availability_is_green_and_offers_are_optional_step_four(): void
    {
        $service = $this->readyService();
        $this->availability($service);

        $progress = app(SchedulingSetupProgress::class)->forService(
            $service,
            'availability',
        );
        $steps = collect($progress['steps'])->keyBy('key');

        $this->assertSame(2, $steps->get('availability')['number']);
        $this->assertSame('current_complete', $steps->get('availability')['state']);
        $this->assertSame('Current', $steps->get('availability')['state_label']);
        $this->assertSame(3, $steps->get('staff')['number']);
        $this->assertTrue($steps->get('staff')['recommended']);
        $this->assertSame(4, $steps->get('offers')['number']);
        $this->assertFalse($steps->get('offers')['required']);
        $this->assertFalse($steps->get('offers')['recommended']);
        $this->assertSame('optional', $steps->get('offers')['state']);
        $this->assertSame(5, $steps->get('ready')['number']);
        $this->assertSame('Set up staff next', $progress['next_action']['label']);

        SchedulingBookingOffer::query()->create([
            'bookable_service_id' => $service->getKey(),
            'code' => 'FREEVA',
            'name' => 'Webinar offer',
            'status' => SchedulingBookingOffer::STATUS_ACTIVE,
        ]);

        $withOffer = app(SchedulingSetupProgress::class)->forService(
            $service,
            'offers',
        );
        $offerStep = collect($withOffer['steps'])->firstWhere('key', 'offers');

        $this->assertTrue($offerStep['complete']);
        $this->assertSame('current_complete', $offerStep['state']);
        $this->assertSame('Current', $offerStep['state_label']);
    }

    public function test_guided_availability_moves_test_availability_to_a_modal_and_surfaces_staff_next(): void
    {
        $user = User::factory()->create();
        $service = $this->readyService();
        $this->availability($service);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.availability.index', [
                'service_id' => $service->getKey(),
                'guided' => 1,
            ]))
            ->assertOk()
            ->assertSee('data-scheduling-progress-step="availability"', false)
            ->assertSee('data-scheduling-progress-state="current_complete"', false)
            ->assertSee('data-scheduling-progress-step="offers"', false)
            ->assertSee('data-availability-guided-next', false)
            ->assertSee('Set up staff next')
            ->assertSee(
                route('crm.scheduling.configuration.services.staff.edit', $service),
                false,
            )
            ->assertSee('data-availability-test-trigger', false)
            ->assertSee('data-availability-test-modal', false);
    }

    public function test_appointment_details_exposes_compact_optional_offer_management(): void
    {
        $user = User::factory()->create();
        $service = $this->readyService();
        SchedulingBookingOffer::query()->create([
            'bookable_service_id' => $service->getKey(),
            'code' => 'FREEVA',
            'name' => 'Webinar offer',
            'status' => SchedulingBookingOffer::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.details.edit', $service))
            ->assertOk()
            ->assertSee('data-scheduling-service-offers-panel', false)
            ->assertSee('FREEVA')
            ->assertSee('Optional')
            ->assertSee('Add offer')
            ->assertSee(
                route('crm.scheduling.configuration.services.offers.index', $service).'#add-offer',
                false,
            );
    }

    private function readyService(): BookableService
    {
        return BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'in_person_arrangement' => null,
            'remote_method' => BookableService::REMOTE_METHOD_VIRTUAL_MEETING,
            'location_type' => BookableService::LOCATION_TYPE_VIRTUAL,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'capacity' => 1,
            'is_public' => true,
        ]);
    }

    private function availability(BookableService $service): SchedulingAvailabilityWindow
    {
        return SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse('2026-09-17 14:00:00 UTC'),
                CarbonImmutable::parse('2026-09-17 17:00:00 UTC'),
            )
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
                'is_available' => true,
            ]);
    }
}