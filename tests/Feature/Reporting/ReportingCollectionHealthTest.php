<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingProjectionCheckpoint;
use App\Modules\Reporting\Services\ReportingCollectionHealthReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportingCollectionHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_health_read_model_reports_collection_attribution_projection_and_pixel_configuration(): void
    {
        Carbon::setTestNow('2026-09-22 18:00:00 UTC');
        config([
            'client.timezone' => 'America/Chicago',
            'reporting.collection.browser_enabled' => true,
            'public_surfaces.tracking.meta_pixel' => [
                'enabled' => true,
                'pixel_id' => '1586087269730616',
                'events' => [
                    'webinar_registration_completed' => 'CompleteRegistration',
                    'scheduling_booking_completed' => 'Schedule',
                ],
            ],
        ]);

        $this->observation(
            surface: 'webinar_registration',
            receivedAt: now('UTC')->subMinutes(10),
            attribution: [
                'utm_source' => 'facebook',
                'external_platform' => 'meta',
                'external_campaign_id' => 'campaign-1',
            ],
        );
        $this->observation(
            surface: 'scheduling_public_booking',
            receivedAt: now('UTC')->subMinutes(5),
        );
        ReportingProjectionCheckpoint::query()->create([
            'projector_key' => ProjectReportingDailyMetricsAction::PROJECTOR_KEY,
            'projector_version' => ProjectReportingDailyMetricsAction::PROJECTOR_VERSION,
            'cursor' => now('UTC')->toDateString(),
            'window_start' => now('UTC')->startOfDay(),
            'window_end' => now('UTC')->endOfDay(),
            'projected_through' => now('UTC')->subMinutes(2),
            'meta' => [
                'days' => 2,
                'metrics' => 14,
            ],
        ]);

        $health = app(ReportingCollectionHealthReadService::class)->read();

        $this->assertSame('positive', $health['overall_status']);
        $this->assertSame('collecting', $health['collection']['status']);
        $this->assertSame(2, $health['collection']['recent_observation_count']);
        $this->assertEqualsCanonicalizing(
            ['webinar_registration', 'scheduling_public_booking'],
            collect($health['collection']['surfaces'])->pluck('surface')->all(),
        );
        $this->assertSame('present', $health['attribution']['status']);
        $this->assertSame(1, $health['attribution']['with_attribution_count']);
        $this->assertSame(1, $health['attribution']['without_attribution_count']);
        $this->assertSame('current', $health['projection']['status']);
        $this->assertSame(0, $health['projection']['lag_seconds']);
        $this->assertSame(14, $health['projection']['metrics_written']);
        $this->assertSame('configured', $health['meta_pixel']['status']);
        $this->assertTrue($health['meta_pixel']['configured']);
        $this->assertSame(2, $health['meta_pixel']['conversion_event_count']);
        $this->assertEqualsCanonicalizing(
            ['webinar_registration_completed', 'scheduling_booking_completed'],
            $health['meta_pixel']['conversion_events'],
        );
        $this->assertEqualsCanonicalizing(
            ['collection', 'attribution', 'projection', 'meta_pixel'],
            collect($health['cards'])->pluck('key')->all(),
        );
    }

    public function test_health_read_model_flags_projection_that_has_fallen_behind_new_browser_collection(): void
    {
        Carbon::setTestNow('2026-09-22 18:00:00 UTC');
        config([
            'reporting.collection.browser_enabled' => true,
            'public_surfaces.tracking.meta_pixel' => [
                'enabled' => false,
                'pixel_id' => null,
                'events' => [],
            ],
        ]);

        $this->observation(
            surface: 'webinar_registration',
            receivedAt: now('UTC')->subMinutes(5),
        );
        ReportingProjectionCheckpoint::query()->create([
            'projector_key' => ProjectReportingDailyMetricsAction::PROJECTOR_KEY,
            'projector_version' => ProjectReportingDailyMetricsAction::PROJECTOR_VERSION,
            'cursor' => now('UTC')->toDateString(),
            'projected_through' => now('UTC')->subMinutes(30),
            'meta' => [],
        ]);

        $health = app(ReportingCollectionHealthReadService::class)->read();

        $this->assertSame('attention', $health['overall_status']);
        $this->assertSame('behind', $health['projection']['status']);
        $this->assertSame(1500, $health['projection']['lag_seconds']);
        $this->assertSame(25, $health['projection']['lag_minutes']);
        $this->assertSame('disabled', $health['meta_pixel']['status']);
    }

    public function test_reporting_workspace_exposes_collection_health_as_a_structural_surface(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/reporting');

        $response
            ->assertOk()
            ->assertViewHas('collectionHealth', function (mixed $health): bool {
                return is_array($health)
                    && isset(
                        $health['overall_status'],
                        $health['collection'],
                        $health['attribution'],
                        $health['projection'],
                        $health['meta_pixel'],
                        $health['cards'],
                    );
            })
            ->assertSee('data-reporting-health', false)
            ->assertSee('data-reporting-health-card="collection"', false)
            ->assertSee('data-reporting-health-card="projection"', false);
    }

    /**
     * @param array<string, mixed> $attribution
     */
    private function observation(
        string $surface,
        Carbon $receivedAt,
        array $attribution = [],
    ): ReportingObservation {
        $eventId = (string) Str::uuid();

        return ReportingObservation::query()->create([
            'event_id' => $eventId,
            'payload_hash' => hash('sha256', $eventId),
            'event_key' => $surface.'.page.view',
            'event_version' => 1,
            'source' => 'browser',
            'occurred_at' => $receivedAt,
            'received_at' => $receivedAt,
            'host' => 'public.example.test',
            'surface' => $surface,
            'path' => '/public',
            'traffic_class' => 'likely_human',
            ...$attribution,
        ]);
    }
}