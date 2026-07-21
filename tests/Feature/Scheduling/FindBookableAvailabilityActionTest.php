<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FindBookableAvailabilityActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_weekly_rules_produce_ordered_host_specific_slots(): void
    {
        CarbonImmutable::setTestNow('2026-03-01 00:00:00 UTC');

        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 30,
            'timezone' => 'UTC',
        ]);
        $host = SchedulingHost::factory()->create([
            'timezone' => 'UTC',
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '12:00:00')
            ->create([
                'timezone' => 'UTC',
                'capacity' => 2,
            ]);

        $slots = $this->find(
            service: $service,
            startsAt: CarbonImmutable::parse('2026-03-02 00:00:00 UTC'),
            endsAt: CarbonImmutable::parse('2026-03-03 00:00:00 UTC'),
        );

        $this->assertCount(5, $slots);
        $this->assertContainsOnlyInstancesOf(BookableSlot::class, $slots);
        $this->assertSame($host->id, $slots[0]->schedulingHostId);
        $this->assertSame('2026-03-02 09:00:00', $slots[0]->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-02 11:00:00', $slots[4]->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame(1, $slots[0]->capacity);
        $this->assertSame(1, $slots[0]->remainingCapacity);
        $this->assertContains('service', $slots[0]->sourceScopes);
    }

    public function test_scope_layers_intersect_and_blackouts_subtract(): void
    {
        CarbonImmutable::setTestNow('2026-03-01 00:00:00 UTC');

        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'capacity' => 4,
            'timezone' => 'UTC',
        ]);
        $host = SchedulingHost::factory()->create([
            'capacity' => 4,
            'timezone' => 'UTC',
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'capacity_override' => 3,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '17:00:00')
            ->create(['timezone' => 'UTC', 'capacity' => 4]);

        SchedulingAvailabilityWindow::factory()
            ->hostWide($host)
            ->weekly(1, '10:00:00', '16:00:00')
            ->create(['timezone' => 'UTC', 'capacity' => 3]);

        SchedulingAvailabilityWindow::factory()
            ->forServiceAndHost($service, $host)
            ->weekly(1, '11:00:00', '15:00:00')
            ->create(['timezone' => 'UTC', 'capacity' => 2]);

        SchedulingAvailabilityWindow::factory()
            ->forServiceAndHost($service, $host)
            ->weekly(1, '12:00:00', '13:00:00')
            ->unavailable()
            ->create(['timezone' => 'UTC']);

        $slots = $this->find(
            service: $service,
            startsAt: CarbonImmutable::parse('2026-03-02 00:00:00 UTC'),
            endsAt: CarbonImmutable::parse('2026-03-03 00:00:00 UTC'),
        );

        $this->assertSame(
            ['11:00', '13:00', '14:00'],
            array_map(
                static fn (BookableSlot $slot): string => $slot->startsAt->format('H:i'),
                $slots,
            ),
        );
        $this->assertSame(2, $slots[0]->capacity);
        $this->assertEqualsCanonicalizing(
            ['service', 'host', 'service_host'],
            $slots[0]->sourceScopes,
        );
    }

    public function test_configured_positive_layers_restrict_even_when_they_do_not_match_the_range(): void
    {
        CarbonImmutable::setTestNow('2026-03-01 00:00:00 UTC');

        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'timezone' => 'UTC',
        ]);
        $host = SchedulingHost::factory()->create(['timezone' => 'UTC']);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '12:00:00')
            ->create(['timezone' => 'UTC']);

        $hostLayer = SchedulingAvailabilityWindow::factory()
            ->hostWide($host)
            ->weekly(2, '09:00:00', '12:00:00')
            ->create(['timezone' => 'UTC']);

        $mondayStartsAt = CarbonImmutable::parse('2026-03-02 00:00:00 UTC');
        $mondayEndsAt = $mondayStartsAt->addDay();

        $this->assertSame([], $this->find(
            service: $service,
            startsAt: $mondayStartsAt,
            endsAt: $mondayEndsAt,
        ));

        $hostLayer->delete();

        $this->assertCount(3, $this->find(
            service: $service,
            startsAt: $mondayStartsAt,
            endsAt: $mondayEndsAt,
        ));
    }

    public function test_absolute_rules_preserve_display_timezone_across_dst(): void
    {
        CarbonImmutable::setTestNow('2026-03-01 00:00:00 UTC');

        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'timezone' => 'America/Chicago',
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse('2026-03-08 07:00:00 UTC'),
                CarbonImmutable::parse('2026-03-08 09:00:00 UTC'),
            )
            ->create([
                'timezone' => 'America/Chicago',
            ]);

        $slots = $this->find(
            service: $service,
            startsAt: CarbonImmutable::parse('2026-03-08 06:00:00 UTC'),
            endsAt: CarbonImmutable::parse('2026-03-08 10:00:00 UTC'),
            displayTimezone: 'America/Chicago',
        );

        $this->assertCount(2, $slots);
        $this->assertSame('2026-03-08 01:00 CST', $slots[0]->localStartsAt()->format('Y-m-d H:i T'));
        $this->assertSame('2026-03-08 03:00 CDT', $slots[1]->localStartsAt()->format('Y-m-d H:i T'));
        $this->assertEquals(60, $slots[0]->startsAt->diffInMinutes($slots[0]->endsAt));
    }

    public function test_notice_and_horizon_boundaries_clip_availability(): void
    {
        $now = CarbonImmutable::parse('2026-07-01 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);

        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 120,
            'booking_horizon_days' => 2,
            'timezone' => 'UTC',
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute($now, $now->addDays(4))
            ->create(['timezone' => 'UTC']);

        $slots = $this->find(
            service: $service,
            startsAt: $now,
            endsAt: $now->addDays(4),
        );

        $this->assertNotEmpty($slots);
        $this->assertSame('2026-07-01 14:00:00', $slots[0]->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-03 11:00:00', $slots[array_key_last($slots)]->startsAt->format('Y-m-d H:i:s'));
        $this->assertTrue($slots[array_key_last($slots)]->endsAt->equalTo($now->addDays(2)));
    }

    public function test_candidate_and_existing_appointment_buffers_block_adjacent_slots(): void
    {
        CarbonImmutable::setTestNow('2026-07-01 00:00:00 UTC');

        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'buffer_before_minutes' => 15,
            'buffer_after_minutes' => 15,
            'timezone' => 'UTC',
        ]);
        $day = CarbonImmutable::parse('2026-07-02 00:00:00 UTC');

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute($day->setTime(9, 0), $day->setTime(13, 0))
            ->create(['timezone' => 'UTC']);

        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => null,
            'starts_at' => $day->setTime(10, 0),
            'ends_at' => $day->setTime(11, 0),
        ]);

        Appointment::factory()->canceled()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => null,
            'starts_at' => $day->setTime(12, 0),
            'ends_at' => $day->setTime(13, 0),
        ]);

        $slots = $this->find(
            service: $service,
            startsAt: $day,
            endsAt: $day->addDay(),
        );

        $this->assertSame(
            ['12:00'],
            array_map(
                static fn (BookableSlot $slot): string => $slot->startsAt->format('H:i'),
                $slots,
            ),
        );
    }

    public function test_capacity_uses_independent_host_and_service_bottlenecks(): void
    {
        CarbonImmutable::setTestNow('2026-07-01 00:00:00 UTC');

        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'capacity' => 3,
            'timezone' => 'UTC',
        ]);
        $otherService = BookableService::factory()->create([
            'capacity' => 3,
            'timezone' => 'UTC',
        ]);
        $host = SchedulingHost::factory()->create([
            'capacity' => 2,
            'timezone' => 'UTC',
        ]);
        $day = CarbonImmutable::parse('2026-07-02 00:00:00 UTC');

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'capacity_override' => 2,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute($day->setTime(9, 0), $day->setTime(10, 0))
            ->create(['timezone' => 'UTC', 'capacity' => 2]);

        Appointment::factory()->create([
            'bookable_service_id' => $otherService->id,
            'scheduling_host_id' => $host->id,
            'starts_at' => $day->setTime(9, 0),
            'ends_at' => $day->setTime(10, 0),
        ]);

        $slots = $this->find(
            service: $service,
            startsAt: $day,
            endsAt: $day->addDay(),
        );

        $this->assertCount(1, $slots);
        $this->assertSame(2, $slots[0]->capacity);
        $this->assertSame(1, $slots[0]->remainingCapacity);

        Appointment::factory()->create([
            'bookable_service_id' => $otherService->id,
            'scheduling_host_id' => $host->id,
            'starts_at' => $day->setTime(9, 0),
            'ends_at' => $day->setTime(10, 0),
        ]);

        $this->assertSame([], $this->find(
            service: $service,
            startsAt: $day,
            endsAt: $day->addDay(),
        ));
    }

    public function test_inactive_assignments_do_not_fall_back_to_unhosted_slots(): void
    {
        CarbonImmutable::setTestNow('2026-07-01 00:00:00 UTC');

        $service = BookableService::factory()->create(['timezone' => 'UTC']);
        $host = SchedulingHost::factory()->create(['timezone' => 'UTC']);
        $day = CarbonImmutable::parse('2026-07-02 00:00:00 UTC');

        BookableServiceHost::factory()->inactive()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute($day->setTime(9, 0), $day->setTime(10, 0))
            ->create(['timezone' => 'UTC']);

        $this->assertSame([], $this->find(
            service: $service,
            startsAt: $day,
            endsAt: $day->addDay(),
        ));

        $unhostedService = BookableService::factory()->create(['timezone' => 'UTC']);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($unhostedService)
            ->absolute($day->setTime(9, 0), $day->setTime(10, 0))
            ->create(['timezone' => 'UTC']);

        $unhostedSlots = $this->find(
            service: $unhostedService,
            startsAt: $day,
            endsAt: $day->addDay(),
        );

        $this->assertCount(1, $unhostedSlots);
        $this->assertNull($unhostedSlots[0]->schedulingHostId);
    }

    public function test_search_contract_rejects_unbounded_ranges(): void
    {
        $service = BookableService::factory()->create(['timezone' => 'UTC']);
        $startsAt = CarbonImmutable::parse('2026-01-01 00:00:00 UTC');

        $this->expectException(InvalidArgumentException::class);

        new AvailabilitySearch(
            service: $service,
            startsAt: $startsAt,
            endsAt: $startsAt->addDays(367),
        );
    }

    public function test_search_contract_rejects_invalid_display_timezones(): void
    {
        $service = BookableService::factory()->create(['timezone' => 'UTC']);
        $startsAt = CarbonImmutable::parse('2026-01-01 00:00:00 UTC');

        $this->expectException(InvalidArgumentException::class);

        new AvailabilitySearch(
            service: $service,
            startsAt: $startsAt,
            endsAt: $startsAt->addDay(),
            displayTimezone: 'Not/A_Timezone',
        );
    }

    /**
     * @return array<int, BookableSlot>
     */
    private function find(
        BookableService $service,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?SchedulingHost $host = null,
        ?string $displayTimezone = null,
    ): array {
        return app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: $startsAt,
                endsAt: $endsAt,
                host: $host,
                displayTimezone: $displayTimezone,
            ),
        );
    }
}