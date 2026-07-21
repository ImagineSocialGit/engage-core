<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SchedulingAvailabilityWindowFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_rule_defaults_are_executable_without_refreshing_the_model(): void
    {
        $service = BookableService::factory()->create();

        $window = SchedulingAvailabilityWindow::query()->create([
            'bookable_service_id' => $service->id,
            'weekday' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);

        $this->assertSame(
            SchedulingAvailabilityWindowType::Weekly,
            $window->window_type,
        );
        $this->assertSame('UTC', $window->timezone);
        $this->assertTrue($window->is_available);
        $this->assertSame(SchedulingAvailabilityWindow::SOURCE_MANUAL, $window->source);
        $this->assertTrue($window->bookableService->is($service));
        $this->assertNull($window->schedulingHost);
    }

    public function test_rules_can_be_service_wide_host_wide_or_service_host_specific(): void
    {
        $service = BookableService::factory()->create();
        $host = SchedulingHost::factory()->create();

        $serviceWide = SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '12:00:00')
            ->create();

        $hostWide = SchedulingAvailabilityWindow::factory()
            ->hostWide($host)
            ->weekly(2, '10:00:00', '16:00:00')
            ->create();

        $serviceHostSpecific = SchedulingAvailabilityWindow::factory()
            ->forServiceAndHost($service, $host)
            ->weekly(3, '11:00:00', '15:00:00')
            ->create();

        $this->assertTrue($service->availabilityWindows->contains($serviceWide));
        $this->assertTrue($service->availabilityWindows->contains($serviceHostSpecific));
        $this->assertTrue($service->serviceWideAvailabilityWindows->contains($serviceWide));
        $this->assertTrue($service->hostScopedAvailabilityWindows->contains($serviceHostSpecific));

        $this->assertTrue($host->availabilityWindows->contains($hostWide));
        $this->assertTrue($host->availabilityWindows->contains($serviceHostSpecific));
        $this->assertTrue($host->hostWideAvailabilityWindows->contains($hostWide));
        $this->assertTrue($host->serviceScopedAvailabilityWindows->contains($serviceHostSpecific));

        $this->assertSame(1, SchedulingAvailabilityWindow::query()->serviceWide()->count());
        $this->assertSame(1, SchedulingAvailabilityWindow::query()->hostWide()->count());
        $this->assertSame(1, SchedulingAvailabilityWindow::query()->serviceHostSpecific()->count());
        $this->assertSame(2, SchedulingAvailabilityWindow::query()->forService($service)->count());
        $this->assertSame(2, SchedulingAvailabilityWindow::query()->forHost($host)->count());
    }

    public function test_absolute_unavailable_rules_represent_bounded_blackout_exceptions(): void
    {
        $service = BookableService::factory()->create();
        $startsAt = CarbonImmutable::parse('2026-08-10 14:00:00', 'UTC');
        $endsAt = $startsAt->addHours(2);

        $window = SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute($startsAt, $endsAt)
            ->unavailable()
            ->create([
                'timezone' => 'America/Chicago',
                'capacity' => null,
            ]);

        $this->assertSame(
            SchedulingAvailabilityWindowType::Absolute,
            $window->window_type,
        );
        $this->assertFalse($window->is_available);
        $this->assertSame('America/Chicago', $window->timezone);
        $this->assertNull($window->weekday);
        $this->assertNull($window->start_time);
        $this->assertNull($window->end_time);
        $this->assertTrue($window->starts_at->equalTo($startsAt));
        $this->assertTrue($window->ends_at->equalTo($endsAt));
        $this->assertSame(1, SchedulingAvailabilityWindow::query()->absolute()->unavailable()->count());
    }

    public function test_malformed_or_targetless_rule_definitions_are_rejected(): void
    {
        $service = BookableService::factory()->create();
        $startsAt = CarbonImmutable::parse('2026-08-10 14:00:00', 'UTC');

        $this->assertInvalidDefinition([
            'bookable_service_id' => null,
            'scheduling_host_id' => null,
            'window_type' => SchedulingAvailabilityWindowType::Weekly,
            'weekday' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ], 'require a service, a host, or both');

        $this->assertInvalidDefinition([
            'bookable_service_id' => $service->id,
            'window_type' => SchedulingAvailabilityWindowType::Weekly,
            'weekday' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ], 'cannot include absolute');

        $this->assertInvalidDefinition([
            'bookable_service_id' => $service->id,
            'window_type' => SchedulingAvailabilityWindowType::Absolute,
            'weekday' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ], 'cannot include weekly');

        $this->assertInvalidDefinition([
            'bookable_service_id' => $service->id,
            'window_type' => SchedulingAvailabilityWindowType::Weekly,
            'weekday' => 1,
            'start_time' => '17:00:00',
            'end_time' => '09:00:00',
        ], 'start_time before end_time');

        $this->assertInvalidDefinition([
            'bookable_service_id' => $service->id,
            'window_type' => SchedulingAvailabilityWindowType::Absolute,
            'starts_at' => $startsAt->addHour(),
            'ends_at' => $startsAt,
        ], 'starts_at before ends_at');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertInvalidDefinition(
        array $attributes,
        string $messageFragment,
    ): void {
        try {
            SchedulingAvailabilityWindow::query()->create($attributes);
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                $messageFragment,
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('Expected an invalid scheduling availability definition to be rejected.');
    }
}