<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Services\SchedulingLocalDateTimeResolver;
use InvalidArgumentException;
use Tests\TestCase;

class SchedulingLocalDateTimeResolverTest extends TestCase
{
    public function test_local_wall_times_resolve_to_one_authoritative_utc_instant(): void
    {
        $resolved = app(SchedulingLocalDateTimeResolver::class)->resolve(
            '2026-08-10T15:30',
            'America/Chicago',
            'check-in time',
        );

        $this->assertSame('2026-08-10T20:30:00.000000Z', $resolved->toISOString());
    }

    public function test_nonexistent_spring_forward_wall_time_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        app(SchedulingLocalDateTimeResolver::class)->resolve(
            '2026-03-08T02:30',
            'America/Chicago',
            'check-in time',
        );
    }

    public function test_ambiguous_fall_back_wall_time_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous');

        app(SchedulingLocalDateTimeResolver::class)->resolve(
            '2026-11-01T01:30',
            'America/Chicago',
            'check-out time',
        );
    }
}