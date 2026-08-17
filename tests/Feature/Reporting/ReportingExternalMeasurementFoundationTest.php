<?php

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Actions\UpsertReportingExternalMeasurementAction;
use App\Modules\Reporting\Data\ReportingExternalMeasurementData;
use App\Modules\Reporting\Models\ReportingExternalMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ReportingExternalMeasurementFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_measurement_upsert_uses_stable_platform_identity_not_names(): void
    {
        $action = new UpsertReportingExternalMeasurementAction();
        $date = CarbonImmutable::parse('2026-08-16', 'America/Chicago');

        $first = $action->handle(new ReportingExternalMeasurementData(
            measurementDate: $date,
            platform: 'Meta',
            campaignId: '12001',
            source: 'manual_csv',
            accountId: 'act-1',
            accountTimezone: 'America/Chicago',
            groupId: '12002',
            creativeId: '12003',
            campaignName: 'Original Campaign Name',
            groupName: 'Original Ad Set',
            creativeName: 'Original Ad Name',
            placement: 'instagram_reels',
            currency: 'usd',
            impressions: 1000,
            reach: 800,
            linkClicks: 120,
            outboundClicks: 110,
            landingPageViews: 90,
            spend: '123.45',
            resultType: 'registration',
            results: '15',
        ));

        $second = $action->handle(new ReportingExternalMeasurementData(
            measurementDate: $date,
            platform: 'meta',
            campaignId: '12001',
            source: 'api',
            accountId: 'act-1',
            accountTimezone: 'America/Chicago',
            groupId: '12002',
            creativeId: '12003',
            campaignName: 'Renamed Campaign',
            groupName: 'Renamed Ad Set',
            creativeName: 'Renamed Ad',
            placement: 'instagram_reels',
            currency: 'USD',
            impressions: 1100,
            spend: '130',
            resultType: 'registration',
            results: '16',
        ));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('reporting_external_measurements', 1);

        $stored = ReportingExternalMeasurement::query()->sole();

        $this->assertSame('meta', $stored->platform);
        $this->assertSame('12001', $stored->campaign_id);
        $this->assertSame('12002', $stored->group_id);
        $this->assertSame('12003', $stored->creative_id);
        $this->assertSame('Renamed Campaign', $stored->campaign_name);
        $this->assertSame('api', $stored->source);
        $this->assertSame(1100, $stored->impressions);
        $this->assertSame('130.0000', $stored->spend);
        $this->assertSame('16.000000', $stored->results);
    }

    public function test_external_measurement_rejects_invalid_timezone_and_negative_counts(): void
    {
        $action = new UpsertReportingExternalMeasurementAction();

        foreach ([
            ['timezone' => 'Central Time', 'impressions' => 1],
            ['timezone' => 'America/Chicago', 'impressions' => -1],
        ] as $case) {
            try {
                $action->handle(new ReportingExternalMeasurementData(
                    measurementDate: CarbonImmutable::parse('2026-08-16'),
                    platform: 'meta',
                    campaignId: '12001',
                    source: 'manual_csv',
                    accountTimezone: $case['timezone'],
                    impressions: $case['impressions'],
                ));

                $this->fail('Expected invalid external measurement data to be rejected.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertDatabaseCount('reporting_external_measurements', 0);
    }
}