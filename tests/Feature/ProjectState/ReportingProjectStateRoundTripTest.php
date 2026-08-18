<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingExternalMeasurement;
use App\Support\ProjectState\ProjectStateDocumentCodec;
use App\Support\ProjectState\ProjectStateManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportingProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_retained_reporting_history_round_trips_without_transferring_ephemeral_reporting_state(): void
    {
        $projectedThrough = CarbonImmutable::parse('2026-08-16 19:45:00 UTC');
        $importedAt = CarbonImmutable::parse('2026-08-17 14:30:00 UTC');
        $measurementIdentityHash = hash('sha256', 'reporting-external-project-state-test');

        ReportingDailyMetric::query()->create([
            'metric_date' => '2026-08-16',
            'metric_key' => 'webinar.registration_conversion',
            'metric_version' => 1,
            'dimension_hash' => hash('sha256', 'reporting-project-state-test'),
            'dimensions' => [
                'traffic_class' => 'likely_human',
                'surface' => 'webinar_registration',
            ],
            'numerator' => 4,
            'denominator' => 20,
            'projected_through' => $projectedThrough,
        ]);

        ReportingExternalMeasurement::query()->create([
            'period_start' => '2026-07-18',
            'period_end' => '2026-08-16',
            'platform' => 'meta',
            'account_id' => 'act_123456789',
            'account_timezone' => 'America/Chicago',
            'campaign_id' => '1200123456789',
            'group_id' => '1200987654321',
            'creative_id' => '1200111122223',
            'campaign_name' => 'First-Time Homebuyer Webinar',
            'group_name' => 'First Time Buyers',
            'creative_name' => 'Know Before You Offer',
            'placement' => 'facebook_feed',
            'identity_quality' => ReportingExternalMeasurement::IDENTITY_STABLE_IDS,
            'currency' => 'USD',
            'impressions' => 22263,
            'reach' => 17702,
            'link_clicks' => 356,
            'outbound_clicks' => 341,
            'landing_page_views' => 413,
            'spend' => '179.0600',
            'result_type' => 'landing_page_view',
            'results' => '413.000000',
            'source' => 'meta_ads_csv',
            'source_file_hash' => hash('sha256', 'meta-export.csv'),
            'meta' => [
                'ad_delivery' => 'active',
                'attribution_setting' => '7-day click or 1-day view',
            ],
            'identity_hash' => $measurementIdentityHash,
            'imported_at' => $importedAt,
        ]);

        $manager = app(ProjectStateManager::class);
        $document = $manager->export();

        $this->assertSame((int) config('project_state.version'), $document['version']);
        $this->assertArrayHasKey('reporting', $document['sections']);
        $this->assertSame(2, $document['sections']['reporting']['version']);
        $this->assertCount(
            1,
            $document['sections']['reporting']['tables']['reporting_daily_metrics'],
        );
        $this->assertCount(
            1,
            $document['sections']['reporting']['tables']['reporting_external_measurements'],
        );
        $this->assertArrayNotHasKey(
            'reporting_sessions',
            $document['sections']['reporting']['tables'],
        );
        $this->assertArrayNotHasKey(
            'reporting_observations',
            $document['sections']['reporting']['tables'],
        );
        $this->assertArrayNotHasKey(
            'reporting_projection_checkpoints',
            $document['sections']['reporting']['tables'],
        );

        DB::table('reporting_daily_metrics')->delete();
        DB::table('reporting_external_measurements')->delete();

        $report = $manager->import($document);

        $this->assertTrue($report['applied']);
        $this->assertSame(
            1,
            $report['applied_counts']['reporting_daily_metrics'],
        );
        $this->assertSame(
            1,
            $report['applied_counts']['reporting_external_measurements'],
        );

        $restoredMetric = ReportingDailyMetric::query()
            ->where('metric_key', 'webinar.registration_conversion')
            ->firstOrFail();

        $this->assertSame(4, $restoredMetric->numerator);
        $this->assertSame(20, $restoredMetric->denominator);
        $this->assertEquals([
            'traffic_class' => 'likely_human',
            'surface' => 'webinar_registration',
        ], $restoredMetric->dimensions);
        $this->assertTrue(
            $restoredMetric->projected_through?->equalTo($projectedThrough) ?? false,
        );

        $restoredMeasurement = ReportingExternalMeasurement::query()
            ->where('identity_hash', $measurementIdentityHash)
            ->firstOrFail();

        $this->assertSame('meta', $restoredMeasurement->platform);
        $this->assertSame('1200123456789', $restoredMeasurement->campaign_id);
        $this->assertSame('1200987654321', $restoredMeasurement->group_id);
        $this->assertSame('1200111122223', $restoredMeasurement->creative_id);
        $this->assertSame(ReportingExternalMeasurement::IDENTITY_STABLE_IDS, $restoredMeasurement->identity_quality);
        $this->assertSame(22263, $restoredMeasurement->impressions);
        $this->assertSame(413, $restoredMeasurement->landing_page_views);
        $this->assertSame('179.0600', $restoredMeasurement->spend);
        $this->assertSame('413.000000', $restoredMeasurement->results);
        $this->assertEquals([
            'ad_delivery' => 'active',
            'attribution_setting' => '7-day click or 1-day view',
        ], $restoredMeasurement->meta);
        $this->assertTrue(
            $restoredMeasurement->imported_at?->equalTo($importedAt) ?? false,
        );
    }

    public function test_optional_reporting_section_may_be_absent_from_a_current_source_document(): void
    {
        $manager = app(ProjectStateManager::class);
        $document = $manager->export();

        unset($document['sections']['reporting']);
        $document['checksum'] = app(ProjectStateDocumentCodec::class)->checksum($document);

        $validation = $manager->validate($document);

        $this->assertTrue($validation['valid']);
        $this->assertSame([], $validation['errors']);

        $report = $manager->import($document);

        $this->assertTrue($report['applied']);
        $this->assertArrayNotHasKey(
            'reporting_daily_metrics',
            $report['applied_counts'],
        );
        $this->assertArrayNotHasKey(
            'reporting_external_measurements',
            $report['applied_counts'],
        );
    }
}