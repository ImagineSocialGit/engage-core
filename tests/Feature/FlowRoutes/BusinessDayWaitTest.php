<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\BusinessCalendar;
use App\Modules\Core\Models\BusinessCalendarExclusion;
use App\Modules\Core\Services\BusinessCalendar\BusinessCalendarDateCalculator;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessDayWaitTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_day_wait_uses_the_shared_calendar_and_client_timezone(): void
    {
        config()->set('client.timezone', 'America/Chicago');

        $calendar = BusinessCalendar::query()->where('key', 'default')->firstOrFail();
        $calendar->exclusions()->create([
            'key' => '33333333-3333-4333-8333-333333333333',
            'name' => 'Christmas Day',
            'recurrence' => BusinessCalendarExclusion::RECURRENCE_ANNUAL,
            'month' => 12,
            'day' => 25,
        ]);

        $calculator = app(BusinessCalendarDateCalculator::class);
        $now = CarbonImmutable::parse('2026-12-24 10:00:00', 'America/Chicago')->utc();
        $definition = WaitPointDefinition::from(
            definition: ['business_days' => 2],
            now: $now,
            businessDayCalculator: fn (
                int $businessDays,
                CarbonImmutable $from,
                string $timezone,
            ): CarbonImmutable => $calculator->addBusinessDays(
                from: $from,
                businessDays: $businessDays,
                timezone: $timezone,
            ),
        );

        $this->assertTrue($definition->isValid());
        $this->assertSame('business_days', $definition->mode);
        $this->assertSame('America/Chicago', $definition->timezone);
        $this->assertSame(
            '2026-12-29 10:00:00',
            $definition->resumeAt?->setTimezone('America/Chicago')->format('Y-m-d H:i:s'),
        );
    }

    public function test_business_day_definition_is_valid_during_setup_validation_without_calculating_a_runtime_date(): void
    {
        $definition = WaitPointDefinition::from([
            'business_days' => 2,
        ]);

        $this->assertTrue($definition->isValid());
        $this->assertSame('business_days', $definition->mode);
        $this->assertNull($definition->resumeAt);
    }
}