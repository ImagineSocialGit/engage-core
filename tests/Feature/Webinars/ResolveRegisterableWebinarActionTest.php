<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction;
use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResolveRegisterableWebinarActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_preserves_the_ten_minute_late_join_window(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $series = WebinarSeries::factory()->create(['status' => 'active']);

        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subMinutes(11),
        ]);

        $lateJoin = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subMinutes(5),
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
        ]);

        $resolved = app(ResolveRegisterableWebinarAction::class)
            ->getForSeries($series);

        $this->assertTrue($resolved?->is($lateJoin));
    }

    public function test_global_resolution_ignores_inactive_series_and_rehydrates_the_cached_occurrence(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $inactiveSeries = WebinarSeries::factory()->create([
            'status' => 'inactive',
        ]);
        $activeSeries = WebinarSeries::factory()->create([
            'status' => 'active',
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $inactiveSeries->getKey(),
            'starts_at' => now()->addMinutes(15),
        ]);

        $activeOccurrence = Webinar::factory()->create([
            'webinar_series_id' => $activeSeries->getKey(),
            'starts_at' => now()->addMinutes(30),
        ]);

        $action = app(GetNextUpcomingWebinarAction::class);

        $this->assertTrue($action->getGlobal()?->is($activeOccurrence));
        $this->assertTrue($action->getGlobal()?->is($activeOccurrence));
    }

    public function test_exact_ten_minute_boundary_is_still_registerable(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $series = WebinarSeries::factory()->create(['status' => 'active']);

        $boundary = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subMinutes(
                ResolveRegisterableWebinarAction::LATE_JOIN_MINUTES,
            ),
        ]);

        $resolved = app(ResolveRegisterableWebinarAction::class)
            ->findForSeries(
                series: $series,
                webinarId: $boundary->getKey(),
            );

        $this->assertTrue($resolved?->is($boundary));
    }

    public function test_find_for_series_resolves_only_the_requested_active_series_occurrence(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $series = WebinarSeries::factory()->create(['status' => 'active']);
        $otherSeries = WebinarSeries::factory()->create(['status' => 'active']);

        $requested = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
        ]);

        $otherOccurrence = Webinar::factory()->create([
            'webinar_series_id' => $otherSeries->getKey(),
            'starts_at' => now()->addMinutes(30),
        ]);

        $resolver = app(ResolveRegisterableWebinarAction::class);

        $this->assertTrue(
            $resolver->findForSeries(
                series: $series,
                webinarId: $requested->getKey(),
            )?->is($requested),
        );

        $this->assertNull(
            $resolver->findForSeries(
                series: $series,
                webinarId: $otherOccurrence->getKey(),
            ),
        );

        $series->update(['status' => 'inactive']);
        $series->refresh();

        $this->assertNull(
            $resolver->findForSeries(
                series: $series,
                webinarId: $requested->getKey(),
            ),
        );
    }

    public function test_cached_occurrence_rolls_forward_after_late_join_window_closes(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $series = WebinarSeries::factory()->create(['status' => 'active']);

        $lateJoin = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subMinutes(9),
        ]);

        $next = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
        ]);

        $action = app(GetNextUpcomingWebinarAction::class);

        $this->assertTrue($action->getForSeries($series)?->is($lateJoin));

        Carbon::setTestNow(now()->addMinutes(2));

        $this->assertTrue($action->getForSeries($series)?->is($next));
    }
}
