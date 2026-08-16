<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Reporting\Models\ReportingDailyMetric;
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

    public function test_retained_reporting_daily_metrics_round_trip_without_transferring_ephemeral_reporting_state(): void
    {
        $projectedThrough = CarbonImmutable::parse('2026-08-16 19:45:00 UTC');

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

        $manager = app(ProjectStateManager::class);
        $document = $manager->export();

        $this->assertSame(11, $document['version']);
        $this->assertArrayHasKey('reporting', $document['sections']);
        $this->assertCount(
            1,
            $document['sections']['reporting']['tables']['reporting_daily_metrics'],
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

        $report = $manager->import($document);

        $this->assertTrue($report['applied']);
        $this->assertSame(
            1,
            $report['applied_counts']['reporting_daily_metrics'],
        );

        $restored = ReportingDailyMetric::query()
            ->where('metric_key', 'webinar.registration_conversion')
            ->firstOrFail();

        $this->assertSame(4, $restored->numerator);
        $this->assertSame(20, $restored->denominator);
        $this->assertEquals([
            'traffic_class' => 'likely_human',
            'surface' => 'webinar_registration',
        ], $restored->dimensions);
        $this->assertTrue(
            $restored->projected_through?->equalTo($projectedThrough) ?? false,
        );
    }
    public function test_optional_reporting_section_may_be_absent_from_a_version_eleven_source_document(): void
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
    }

}