<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\BusinessCalendar;
use App\Modules\Core\Models\BusinessCalendarExclusion;
use App\Modules\Core\Services\BusinessCalendar\BusinessCalendarDateCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_calendar_skips_weekends(): void
    {
        $calendar = BusinessCalendar::query()->where('key', 'default')->firstOrFail();

        $this->assertTrue($calendar->is_default);
        $this->assertEqualsCanonicalizing([6, 7], $calendar->skippedWeekdays());
    }

    public function test_business_day_calculation_skips_weekdays_and_both_exclusion_types(): void
    {
        config()->set('client.timezone', 'America/Chicago');

        $calendar = BusinessCalendar::query()->where('key', 'default')->firstOrFail();

        $calendar->exclusions()->createMany([
            [
                'key' => '11111111-1111-4111-8111-111111111111',
                'name' => 'Christmas Day',
                'recurrence' => BusinessCalendarExclusion::RECURRENCE_ANNUAL,
                'month' => 12,
                'day' => 25,
            ],
            [
                'key' => '22222222-2222-4222-8222-222222222222',
                'name' => 'Office closure',
                'recurrence' => BusinessCalendarExclusion::RECURRENCE_ONCE,
                'exact_date' => '2026-12-28',
            ],
        ]);

        $resumeAt = app(BusinessCalendarDateCalculator::class)->addBusinessDays(
            from: CarbonImmutable::parse('2026-12-24 10:00:00', 'America/Chicago'),
            businessDays: 2,
        );

        $this->assertSame(
            '2026-12-30 10:00:00',
            $resumeAt->setTimezone('America/Chicago')->format('Y-m-d H:i:s'),
        );
    }

    public function test_business_calendar_can_be_updated_without_exposing_internal_identifiers(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->put(route('crm.business-calendar.update'), [
                'skipped_weekdays' => [5, 6, 7],
                'exclusions' => [
                    [
                        'name' => 'Christmas Day',
                        'recurrence' => BusinessCalendarExclusion::RECURRENCE_ANNUAL,
                        'month' => 12,
                        'day' => 25,
                    ],
                    [
                        'name' => 'Office closure',
                        'recurrence' => BusinessCalendarExclusion::RECURRENCE_ONCE,
                        'exact_date' => '2026-11-27',
                    ],
                ],
            ]);

        $response->assertRedirect(route('crm.business-calendar.edit'));

        $calendar = BusinessCalendar::query()->where('key', 'default')->firstOrFail();

        $this->assertEqualsCanonicalizing([5, 6, 7], $calendar->skippedWeekdays());
        $this->assertSame(2, $calendar->exclusions()->count());
        $this->assertDatabaseHas('business_calendar_exclusions', [
            'business_calendar_id' => $calendar->getKey(),
            'name' => 'Christmas Day',
            'recurrence' => BusinessCalendarExclusion::RECURRENCE_ANNUAL,
            'month' => 12,
            'day' => 25,
            'exact_date' => null,
        ]);
        $this->assertDatabaseHas('business_calendar_exclusions', [
            'business_calendar_id' => $calendar->getKey(),
            'name' => 'Office closure',
            'recurrence' => BusinessCalendarExclusion::RECURRENCE_ONCE,
            'exact_date' => '2026-11-27',
            'month' => null,
            'day' => null,
        ]);
    }

    public function test_calendar_rejects_skipping_every_weekday(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('crm.business-calendar.edit'))
            ->put(route('crm.business-calendar.update'), [
                'skipped_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            ])
            ->assertRedirect(route('crm.business-calendar.edit'))
            ->assertSessionHasErrors('skipped_weekdays');

        $calendar = BusinessCalendar::query()->where('key', 'default')->firstOrFail();

        $this->assertEqualsCanonicalizing([6, 7], $calendar->skippedWeekdays());
    }
}