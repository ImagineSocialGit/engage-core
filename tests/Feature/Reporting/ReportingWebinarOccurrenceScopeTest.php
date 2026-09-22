<?php

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingSession;
use App\Modules\Reporting\Services\ReportingWorkspaceReadService;
use App\Support\Reporting\ReportingProjectionFactRegistry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportingWebinarOccurrenceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_funnel_metrics_are_projected_and_read_by_webinar_occurrence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 14:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $session = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'occurrence-scope-session'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => now()->subHour(),
            'last_seen_at' => now()->subMinutes(5),
            'absolute_expires_at' => now()->addHour(),
            'landing_path' => '/va-homebuyer-game-plan',
            'traffic_class' => 'likely_human',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 3,
            'classification_reasons' => ['browser_family_recognized'],
            'device_class' => 'desktop',
            'browser_family' => 'Chrome',
            'os_family' => 'Linux',
        ]);

        foreach ([
            'webinar.page.view',
            'webinar.form.start',
        ] as $eventKey) {
            ReportingObservation::query()->create([
                'event_id' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', $eventKey),
                'reporting_session_id' => $session->getKey(),
                'event_key' => $eventKey,
                'event_version' => 1,
                'source' => 'browser',
                'occurred_at' => now()->subMinutes(30),
                'received_at' => now()->subMinutes(30),
                'host' => 'webinar.example.test',
                'surface' => 'webinar_registration',
                'path' => '/va-homebuyer-game-plan',
                'traffic_class' => 'likely_human',
                'classifier_key' => 'browser_request_signals',
                'classifier_version' => 3,
                'classification_reasons' => ['browser_family_recognized'],
                'device_class' => 'desktop',
                'browser_family' => 'Chrome',
                'os_family' => 'Linux',
                'properties' => [
                    'page_revision' => 'slam-dunk-v1',
                    'presentation' => 'modal',
                    'series_id' => 11,
                    'series_slug' => 'va-homebuyer-game-plan',
                    'occurrence_id' => 77,
                    'occurrence_slug' => 'va-homebuyer-game-plan-sep-23',
                ],
            ]);
        }

        $date = CarbonImmutable::now('America/Chicago')->startOfDay();

        (new ProjectReportingDailyMetricsAction(
            new ReportingProjectionFactRegistry([]),
        ))->handle($date, $date);

        $occurrenceLanding = ReportingDailyMetric::query()
            ->where('metric_key', 'webinar.landing_sessions')
            ->get()
            ->first(function (ReportingDailyMetric $metric): bool {
                $dimensions = is_array($metric->dimensions)
                    ? $metric->dimensions
                    : [];

                return ($dimensions['slice'] ?? null) === 'occurrence'
                    && (int) ($dimensions['occurrence_id'] ?? 0) === 77
                    && ($dimensions['traffic_class'] ?? null) === 'likely_human';
            });

        $this->assertNotNull($occurrenceLanding);
        $this->assertSame(1, $occurrenceLanding->numerator);

        $this->metric(
            metricKey: 'webinar.local_registrations',
            dimensions: [
                'slice' => 'occurrence',
                'series_id' => '11',
                'series_slug' => 'va-homebuyer-game-plan',
                'occurrence_id' => '77',
                'occurrence_slug' => 'va-homebuyer-game-plan-sep-23',
            ],
            numerator: 38,
        );
        $this->metric(
            metricKey: 'webinar.attributed_registrations',
            dimensions: [
                'slice' => 'occurrence',
                'series_id' => '11',
                'series_slug' => 'va-homebuyer-game-plan',
                'occurrence_id' => '77',
                'occurrence_slug' => 'va-homebuyer-game-plan-sep-23',
            ],
            numerator: 4,
        );
        $this->metric(
            metricKey: 'webinar.registration_traffic_class',
            dimensions: [
                'slice' => 'occurrence',
                'series_id' => '11',
                'series_slug' => 'va-homebuyer-game-plan',
                'occurrence_id' => '77',
                'occurrence_slug' => 'va-homebuyer-game-plan-sep-23',
                'traffic_class' => 'likely_human',
            ],
            numerator: 3,
        );
        $this->metric(
            metricKey: 'webinar.registration_traffic_class',
            dimensions: [
                'slice' => 'occurrence',
                'series_id' => '11',
                'series_slug' => 'va-homebuyer-game-plan',
                'occurrence_id' => '77',
                'occurrence_slug' => 'va-homebuyer-game-plan-sep-23',
                'traffic_class' => 'likely_automated',
            ],
            numerator: 1,
        );

        $report = app(ReportingWorkspaceReadService::class)
            ->webinarRegistration(30, 77);

        $this->assertSame(77, $report['scope']['occurrence_id']);
        $this->assertSame(1, $report['summary']['likely_human_sessions']);
        $this->assertSame(38, $report['measurement_coverage']['local_registrations']);
        $this->assertSame(
            4,
            $report['measurement_coverage']['browser_correlated_registrations'],
        );
        $this->assertSame(
            34,
            $report['measurement_coverage']['outside_browser_measurement'],
        );
        $this->assertSame(3, $report['registration_traffic']['likely_human']);
        $this->assertSame(1, $report['registration_traffic']['likely_automated']);
        $this->assertSame(0, $report['registration_traffic']['unknown']);
        $this->assertSame(34, $report['registration_traffic']['uncorrelated']);
    }

    /**
     * @param array<string, scalar|null> $dimensions
     */
    private function metric(
        string $metricKey,
        array $dimensions,
        int $numerator,
        ?int $denominator = null,
    ): void {
        $canonical = $dimensions;
        ksort($canonical);

        ReportingDailyMetric::query()->create([
            'metric_date' => now()
                ->timezone('America/Chicago')
                ->toDateString(),
            'metric_key' => $metricKey,
            'metric_version' => ProjectReportingDailyMetricsAction::METRIC_VERSION,
            'dimension_hash' => hash(
                'sha256',
                json_encode($canonical, JSON_THROW_ON_ERROR),
            ),
            'dimensions' => $dimensions,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'projected_through' => now(),
        ]);
    }
}