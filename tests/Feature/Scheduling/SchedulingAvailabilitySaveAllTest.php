<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SchedulingAvailabilitySaveAllTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableScheduling();
    }

    public function test_save_all_persists_regular_hours_and_booking_timing_in_one_workspace_action(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'slot_interval_minutes' => 15,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'remote_method' => BookableService::REMOTE_METHOD_PHONE,
        ]);
        $version = $service->updated_at?->toISOString();

        $response = $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.availability.workspace', $service),
                [
                    'save_section' => 'all',
                    'current_version' => $version,
                    'regular_hours' => $this->regularHours([
                        1 => [
                            ['start' => '09:00', 'end' => '12:00'],
                            ['start' => '13:00', 'end' => '17:00'],
                        ],
                        3 => [
                            ['start' => '10:00', 'end' => '15:00'],
                        ],
                    ]),
                    'slot_interval_choice' => '120',
                    'slot_interval_custom_minutes' => null,
                    'slot_start_anchor_time' => '09:00',
                    'buffer_before_minutes' => 15,
                    'buffer_after_minutes' => 30,
                ],
            );

        $response
            ->assertRedirect(route('crm.scheduling.configuration.availability.index', [
                'service_id' => $service->getKey(),
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('availability_scroll_to', 'availability-workspace')
            ->assertSessionHas('success', 'Availability changes saved.');

        $service->refresh();

        $this->assertSame(120, $service->slot_interval_minutes);
        $this->assertSame(15, $service->buffer_before_minutes);
        $this->assertSame(30, $service->buffer_after_minutes);
        $this->assertSame('09:00', $service->slotStartAnchorTime());

        $windows = SchedulingAvailabilityWindow::query()
            ->where('bookable_service_id', $service->getKey())
            ->whereNull('scheduling_host_id')
            ->where('window_type', SchedulingAvailabilityWindowType::Weekly->value)
            ->where('is_available', true)
            ->whereNull('capacity')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        $this->assertCount(3, $windows);
        $this->assertSame(1, $windows[0]->weekday);
        $this->assertSame('09:00:00', $windows[0]->start_time);
        $this->assertSame('12:00:00', $windows[0]->end_time);
        $this->assertSame(1, $windows[1]->weekday);
        $this->assertSame('13:00:00', $windows[1]->start_time);
        $this->assertSame('17:00:00', $windows[1]->end_time);
        $this->assertSame(3, $windows[2]->weekday);
        $this->assertSame('10:00:00', $windows[2]->start_time);
        $this->assertSame('15:00:00', $windows[2]->end_time);
    }

    public function test_individual_workspace_save_returns_to_the_section_that_was_saved(): void
    {
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);

        $this->actingAs(User::factory()->create())
            ->put(
                route('crm.scheduling.configuration.availability.workspace', $service),
                [
                    'save_section' => 'regular_hours',
                    'current_version' => $service->updated_at?->toISOString(),
                    'regular_hours' => $this->regularHours([
                        1 => [
                            ['start' => '09:00', 'end' => '17:00'],
                        ],
                    ]),
                    'slot_interval_choice' => '15',
                    'slot_interval_custom_minutes' => null,
                    'slot_start_anchor_time' => '09:00',
                    'buffer_before_minutes' => 0,
                    'buffer_after_minutes' => 0,
                ],
            )
            ->assertSessionHasNoErrors()
            ->assertSessionHas('availability_scroll_to', 'regular-hours')
            ->assertSessionHas('success', 'Regular hours updated.');
    }

    /**
     * @param array<int, array<int, array{start: string, end: string}>> $rangesByWeekday
     * @return array<int, array{weekday: int, ranges: array<int, array{start: string, end: string}>}>
     */
    private function regularHours(array $rangesByWeekday): array
    {
        $days = [];

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $days[$weekday] = [
                'weekday' => $weekday,
                'ranges' => $rangesByWeekday[$weekday] ?? [],
            ];
        }

        return $days;
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