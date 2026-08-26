<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\BusinessCalendar;
use App\Modules\Core\Models\BusinessCalendarExclusion;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessCalendarProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_business_calendar_and_exclusions_round_trip_with_core_project_state(): void
    {
        $calendar = BusinessCalendar::query()->where('key', 'default')->firstOrFail();
        $calendar->forceFill(['skipped_weekdays' => [5, 6, 7]])->save();
        $calendar->exclusions()->create([
            'key' => '44444444-4444-4444-8444-444444444444',
            'name' => 'Christmas Day',
            'recurrence' => BusinessCalendarExclusion::RECURRENCE_ANNUAL,
            'month' => 12,
            'day' => 25,
        ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame((int) config('project_state.version'), $document['version']);
        $this->assertSame(
            (int) config('project_state.sections.core.version'),
            $document['sections']['core']['version'],
        );
        $this->assertCount(1, $document['sections']['core']['tables']['business_calendars']);
        $this->assertCount(1, $document['sections']['core']['tables']['business_calendar_exclusions']);

        DB::table('business_calendar_exclusions')->delete();
        DB::table('business_calendars')->delete();
        $replacementCalendarId = DB::table('business_calendars')->insertGetId([
            'key' => 'default',
            'name' => 'Business days',
            'skipped_weekdays' => json_encode([6, 7], JSON_THROW_ON_ERROR),
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid'], implode(PHP_EOL, $report['errors']));

        $projectState->import($document);

        $calendar = BusinessCalendar::query()->where('key', 'default')->firstOrFail();

        $this->assertSame($replacementCalendarId, $calendar->getKey());
        $this->assertEqualsCanonicalizing([5, 6, 7], $calendar->skippedWeekdays());
        $this->assertDatabaseHas('business_calendar_exclusions', [
            'business_calendar_id' => $replacementCalendarId,
            'key' => '44444444-4444-4444-8444-444444444444',
            'name' => 'Christmas Day',
            'recurrence' => BusinessCalendarExclusion::RECURRENCE_ANNUAL,
            'month' => 12,
            'day' => 25,
        ]);
    }
}