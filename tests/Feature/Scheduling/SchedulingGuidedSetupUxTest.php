<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SchedulingGuidedSetupUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-13 12:00:00 UTC');
        config()->set('client.timezone', 'UTC');
        $this->enableScheduling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_first_run_has_one_setup_action_and_progress_status(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.scheduling.index'))
            ->assertRedirect(route('crm.scheduling.configuration.services.index'));

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.index'))
            ->assertOk()
            ->assertSee('data-scheduling-setup-progress', false)
            ->assertSee('data-scheduling-progress-step="appointment_type"', false)
            ->assertSee('data-scheduling-progress-state="current"', false)
            ->assertSee('Add the first appointment type')
            ->assertDontSee('Manage availability')
            ->assertDontSee('data-scheduling-progress-link', false)
            ->assertDontSee('data-scheduling-services-list', false);
    }

    public function test_first_appointment_type_goes_directly_to_guided_availability_and_later_types_open_their_setup(): void
    {
        $user = User::factory()->create();

        $firstResponse = $this->actingAs($user)
            ->post(route('crm.scheduling.configuration.services.store'), [
                'name' => 'Initial Consultation',
                'duration_minutes' => 60,
            ]);

        $first = BookableService::query()->sole();

        $firstResponse
            ->assertRedirect(route('crm.scheduling.configuration.availability.index', [
                'service_id' => $first->getKey(),
                'guided' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('09:00', $first->slotStartAnchorTime());

        $secondResponse = $this->actingAs($user)
            ->post(route('crm.scheduling.configuration.services.store'), [
                'name' => 'Follow-up Call',
                'duration_minutes' => 30,
            ]);

        $second = BookableService::query()
            ->where('name', 'Follow-up Call')
            ->sole();

        $secondResponse
            ->assertRedirect(route(
                'crm.scheduling.configuration.services.edit',
                $second,
            ))
            ->assertSessionHasNoErrors();
    }

    public function test_availability_has_all_service_service_specific_and_guided_presentations(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'name' => 'Planning Call',
            'status' => BookableService::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.availability.index'))
            ->assertOk()
            ->assertSee('data-availability-all-services', false)
            ->assertSee('data-availability-overview-service="'.$service->getKey().'"', false)
            ->assertDontSee('data-availability-regular-hours', false);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.availability.index', [
                'service_id' => $service->getKey(),
            ]))
            ->assertOk()
            ->assertSee('data-availability-service-selector', false)
            ->assertSee('data-scheduling-setup-progress', false)
            ->assertSee('data-availability-regular-hours', false)
            ->assertSee('data-availability-booking-timing', false);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.availability.index', [
                'service_id' => $service->getKey(),
                'guided' => 1,
            ]))
            ->assertOk()
            ->assertSee('data-availability-guided-first-time', false)
            ->assertSee('data-scheduling-progress-state="current"', false)
            ->assertDontSee('data-availability-service-selector', false);
    }

    public function test_booking_timing_supports_two_hour_start_pattern_with_a_business_time_anchor(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'name' => 'Strategy Session',
            'duration_minutes' => 60,
            'slot_interval_minutes' => 15,
            'timezone' => 'UTC',
            'meta' => null,
        ]);

        $this->assertSame('09:00', $service->slotStartAnchorTime());

        $this->actingAs($user)
            ->put(
                route(
                    'crm.scheduling.configuration.availability.booking-timing',
                    $service,
                ),
                [
                    'current_version' => $service->updated_at?->toISOString(),
                    'slot_interval_choice' => '120',
                    'slot_interval_custom_minutes' => null,
                    'slot_start_anchor_time' => '09:00',
                    'buffer_before_minutes' => 10,
                    'buffer_after_minutes' => 20,
                ],
            )
            ->assertRedirect(route('crm.scheduling.configuration.availability.index', [
                'service_id' => $service->getKey(),
            ]))
            ->assertSessionHasNoErrors();

        $service->refresh();

        $this->assertSame(120, $service->slot_interval_minutes);
        $this->assertSame(10, $service->buffer_before_minutes);
        $this->assertSame(20, $service->buffer_after_minutes);
        $this->assertSame('09:00', $service->slotStartAnchorTime());

        SchedulingAvailabilityWindow::factory()
            ->weekly(
                weekday: 1,
                startTime: '09:00:00',
                endTime: '17:00:00',
            )
            ->serviceWide($service)
            ->create([
                'timezone' => 'UTC',
                'capacity' => null,
            ]);

        $slots = app(SchedulingReadService::class)->availabilityForDate(
            service: $service,
            date: CarbonImmutable::parse('2026-09-14 00:00:00 UTC'),
        );

        $this->assertSame(
            ['09:00', '11:00', '13:00', '15:00'],
            array_map(
                static fn ($slot): string => $slot->startsAt->format('H:i'),
                $slots,
            ),
        );
    }

    public function test_shared_capacity_page_explains_when_the_advanced_feature_is_useful(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.configuration.resources.index'))
            ->assertOk()
            ->assertSee('Rooms, Equipment &amp; Shared Capacity', false)
            ->assertSee('Most businesses do not need this page.')
            ->assertSee('One conference room')
            ->assertSee('Two courts or work areas')
            ->assertSee('Specialized equipment')
            ->assertSee('Limited vehicles');
    }

    private function enableScheduling(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));

        if (! $this->app->getProvider(SchedulingModuleServiceProvider::class)) {
            $this->app->register(SchedulingModuleServiceProvider::class);
        }
    }
}