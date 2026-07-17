<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ResolveRegisterableWebinarActionTest extends TestCase
{
    use RefreshDatabase;

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
}
