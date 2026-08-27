<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Services\SchedulingAvailableStartRangeBuilder;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class SchedulingAvailableStartRangeBuilderTest extends TestCase
{
    public function test_consecutive_start_times_are_grouped_without_losing_exact_slots(): void
    {
        $builder = app(SchedulingAvailableStartRangeBuilder::class);
        $slots = [
            $this->slot('09:00', remaining: 2),
            $this->slot('09:15', remaining: 2),
            $this->slot('09:30', remaining: 2),
            $this->slot('10:00', remaining: 2),
        ];

        $ranges = $builder->build($slots, 15);

        $this->assertSame(2, count($ranges));
        $this->assertTrue($ranges[0]['starts_at']->equalTo(CarbonImmutable::parse('2026-08-27 09:00:00 UTC')));
        $this->assertTrue($ranges[0]['last_start_at']->equalTo(CarbonImmutable::parse('2026-08-27 09:30:00 UTC')));
        $this->assertSame(3, $ranges[0]['slot_count']);
        $this->assertSame(3, count($ranges[0]['slots']));
        $this->assertTrue($ranges[1]['starts_at']->equalTo(CarbonImmutable::parse('2026-08-27 10:00:00 UTC')));
        $this->assertSame(1, $ranges[1]['slot_count']);
    }

    public function test_ranges_split_when_open_capacity_changes(): void
    {
        $ranges = app(SchedulingAvailableStartRangeBuilder::class)->build([
            $this->slot('09:00', remaining: 2),
            $this->slot('09:15', remaining: 1),
            $this->slot('09:30', remaining: 1),
        ], 15);

        $this->assertSame(2, count($ranges));
        $this->assertSame(2, $ranges[0]['remaining_capacity']);
        $this->assertSame(1, $ranges[0]['slot_count']);
        $this->assertSame(1, $ranges[1]['remaining_capacity']);
        $this->assertSame(2, $ranges[1]['slot_count']);
    }

    public function test_invalid_slot_interval_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SchedulingAvailableStartRangeBuilder::class)->build([
            $this->slot('09:00'),
        ], 0);
    }

    private function slot(string $time, int $remaining = 1): BookableSlot
    {
        $startsAt = CarbonImmutable::parse("2026-08-27 {$time}:00 UTC");

        return new BookableSlot(
            bookableServiceId: 10,
            schedulingHostId: 20,
            startsAt: $startsAt,
            endsAt: $startsAt->addHour(),
            displayTimezone: 'UTC',
            capacity: 2,
            remainingCapacity: $remaining,
            sourceScopes: ['service'],
            sourceWindowIds: [100],
        );
    }
}