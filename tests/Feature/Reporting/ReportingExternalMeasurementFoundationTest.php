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

    public function test_external_measurement_upsert_uses_period_and_stable_platform_identity_not_names_or_result_type(): void
    {
        $action = new UpsertReportingExternalMeasurementAction();
        $start = CarbonImmutable::parse('2026-08-01');
        $end = CarbonImmutable::parse('2026-08-16');

        $first = $action->handle(new ReportingExternalMeasurementData(
            periodStart: $start,
            periodEnd: $end,
            platform: 'Meta',
            source: 'meta_ads_csv',
            accountId: 'act-1',
            accountTimezone: 'America/Chicago',
            campaignId: '12001',
            groupId: '12002',
            creativeId: '12003',
            campaignName: 'Original Campaign Name',
            groupName: 'Original Ad Set',
            creativeName: 'Original Ad Name',
            placement: 'instagram_reels',
            currency: 'usd',
            impressions: 1000,
            outboundClicks: 90,
            spend: '123.45',
            resultType: 'link_click',
            results: '15',
        ));

        $second = $action->handle(new ReportingExternalMeasurementData(
            periodStart: $start,
            periodEnd: $end,
            platform: 'meta',
            source: 'api',
            accountId: 'act-1',
            accountTimezone: 'America/Chicago',
            campaignId: '12001',
            groupId: '12002',
            creativeId: '12003',
            campaignName: 'Renamed Campaign',
            groupName: 'Renamed Ad Set',
            creativeName: 'Renamed Ad',
            placement: 'instagram_reels',
            currency: 'USD',
            impressions: 1100,
            spend: '130',
            resultType: 'landing_page_view',
            results: '16',
        ));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('reporting_external_measurements', 1);

        $stored = ReportingExternalMeasurement::query()->sole();
        $this->assertSame('2026-08-01', $stored->period_start?->toDateString());
        $this->assertSame('2026-08-16', $stored->period_end?->toDateString());
        $this->assertSame('meta', $stored->platform);
        $this->assertSame('12003', $stored->creative_id);
        $this->assertSame(ReportingExternalMeasurement::IDENTITY_STABLE_IDS, $stored->identity_quality);
        $this->assertSame('Renamed Campaign', $stored->campaign_name);
        $this->assertSame('landing_page_view', $stored->result_type);
        $this->assertSame('api', $stored->source);
        $this->assertSame(1100, $stored->impressions);
        $this->assertSame(90, $stored->outbound_clicks);
        $this->assertSame('130.0000', $stored->spend);
    }

    public function test_external_measurement_accepts_name_fallback_when_export_has_names_but_no_ids(): void
    {
        $stored = app(UpsertReportingExternalMeasurementAction::class)->handle(
            new ReportingExternalMeasurementData(
                periodStart: CarbonImmutable::parse('2026-07-18'),
                periodEnd: CarbonImmutable::parse('2026-08-16'),
                platform: 'meta',
                source: 'meta_ads_csv',
                groupName: 'Name-only Ad Set',
                creativeName: 'Name-only Ad',
                currency: 'USD',
                spend: '59.04',
            ),
        );

        $this->assertSame(ReportingExternalMeasurement::IDENTITY_NAME_FALLBACK, $stored->identity_quality);
        $this->assertNull($stored->campaign_id);
        $this->assertSame('Name-only Ad', $stored->creative_name);
    }

    public function test_external_measurement_rejects_invalid_period_timezone_and_negative_counts(): void
    {
        $action = new UpsertReportingExternalMeasurementAction();

        foreach ([
            ['start' => '2026-08-17', 'end' => '2026-08-16', 'timezone' => 'America/Chicago', 'impressions' => 1],
            ['start' => '2026-08-16', 'end' => '2026-08-16', 'timezone' => 'Central Time', 'impressions' => 1],
            ['start' => '2026-08-16', 'end' => '2026-08-16', 'timezone' => 'America/Chicago', 'impressions' => -1],
        ] as $case) {
            try {
                $action->handle(new ReportingExternalMeasurementData(
                    periodStart: CarbonImmutable::parse($case['start']),
                    periodEnd: CarbonImmutable::parse($case['end']),
                    platform: 'meta',
                    source: 'manual_csv',
                    creativeName: 'Test Ad',
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