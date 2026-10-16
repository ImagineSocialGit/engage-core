<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingExternalMeasurement;
use App\Modules\Reporting\Models\ReportingProjectionCheckpoint;
use App\Support\Modules\ModuleManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_navigation_and_crm_workspace_are_module_owned_and_client_facing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 20:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $this->seedReportMetrics();
        $this->seedExternalMeasurement();

        $navigation = collect(app(ModuleManager::class)->navigationItems())
            ->firstWhere('route', 'crm.reporting.index');

        $this->assertIsArray($navigation);
        $this->assertSame('reporting', $navigation['module']);
        $this->assertSame('Reporting', $navigation['label']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/reporting?days=30');

        $response
            ->assertOk();
    }


    public function test_reporting_workspace_can_refresh_recent_projection_on_demand(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 20:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post('http://crm.'.config('app.root_domain').'/reporting/refresh', [
                'days' => 7,
            ]);

        $response
            ->assertRedirect(route('crm.reporting.index', ['days' => 7]))
            ->assertSessionHas('success', 'Recent Reporting data refreshed.');

        $checkpoint = ReportingProjectionCheckpoint::query()
            ->where('projector_key', 'public_funnel')
            ->where('projector_version', ProjectReportingDailyMetricsAction::PROJECTOR_VERSION)
            ->first();

        $this->assertNotNull($checkpoint);
        $this->assertSame('2026-08-16', $checkpoint->cursor);
        $this->assertSame(2, data_get($checkpoint->meta, 'days'));
    }

    public function test_reporting_workspace_defaults_invalid_ranges_and_is_hidden_when_module_is_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 20:00:00 UTC'));
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/reporting?days=999')
            ->assertOk();

        config([
            'modules.enabled' => array_values(array_diff(
                config('modules.enabled', []),
                ['reporting'],
            )),
        ]);

        $this->assertNull(
            collect(app(ModuleManager::class)->navigationItems())
                ->firstWhere('route', 'crm.reporting.index'),
        );

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/reporting')
            ->assertNotFound();

        $this->actingAs($user)
            ->post('http://crm.'.config('app.root_domain').'/reporting/refresh', [
                'days' => 30,
            ])
            ->assertNotFound();
    }

    private function seedExternalMeasurement(): void
    {
        ReportingExternalMeasurement::query()->create([
            'period_start' => '2026-08-16',
            'period_end' => '2026-08-16',
            'platform' => 'meta',
            'campaign_id' => 'cmp-100',
            'group_id' => 'grp-200',
            'creative_id' => 'ad-300',
            'campaign_name' => 'August Homebuyer',
            'group_name' => 'First Time Buyers',
            'creative_name' => 'Creative A',
            'placement' => 'facebook_feed',
            'identity_quality' => ReportingExternalMeasurement::IDENTITY_STABLE_IDS,
            'currency' => 'USD',
            'impressions' => 1000,
            'link_clicks' => 30,
            'landing_page_views' => 25,
            'spend' => '100.0000',
            'result_type' => 'landing_page_view',
            'results' => '25.000000',
            'source' => 'meta_ads_csv',
            'identity_hash' => hash('sha256', 'workspace-external'),
            'imported_at' => now(),
        ]);
    }


    private function seedSchedulingMetrics(): void
    {
        $date = '2026-08-16';
        $campaign = [
            'slice' => 'campaign',
            'utm_source' => 'meta',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'august_homebuyer',
            'utm_content' => 'creative_a',
            'utm_term' => 'first_time_buyers',
            'external_platform' => 'meta',
            'external_campaign_id' => 'cmp-100',
            'external_group_id' => 'grp-200',
            'external_creative_id' => 'ad-300',
            'external_placement' => 'facebook_feed',
        ];

        foreach ([
            ['scheduling.landing_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human'], 40, null],
            ['scheduling.landing_sessions', ['slice' => 'all', 'traffic_class' => 'unknown'], 10, null],
            ['scheduling.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'catalog'], 40, null],
            ['scheduling.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'service_selected'], 35, null],
            ['scheduling.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'availability_viewed'], 30, null],
            ['scheduling.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'time_selected'], 18, null],
            ['scheduling.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'details_started'], 15, null],
            ['scheduling.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'submit_attempt'], 12, null],
            ['scheduling.booking_conversion', ['slice' => 'all', 'traffic_class' => 'likely_human'], 8, 40],
            ['scheduling.validation_failure_rate', ['slice' => 'all', 'traffic_class' => 'likely_human'], 3, 12],
            ['scheduling.validation_failures', ['slice' => 'all', 'field_key' => 'phone'], 2, null],
            ['scheduling.availability_outcomes', ['slice' => 'all', 'outcome' => 'available'], 25, null],
            ['scheduling.availability_outcomes', ['slice' => 'all', 'outcome' => 'empty'], 5, null],
            ['scheduling.verification_channels', ['slice' => 'all', 'stage' => 'requested', 'channel' => 'email'], 10, null],
            ['scheduling.verification_channels', ['slice' => 'all', 'stage' => 'completed', 'channel' => 'email'], 8, null],
            ['scheduling.public_appointments', ['slice' => 'all'], 8, null],
            ['scheduling.appointment_outcomes', ['slice' => 'all', 'outcome' => 'scheduled'], 8, null],
            ['scheduling.booking_correlation_coverage', ['slice' => 'all'], 8, 8],
            ['scheduling.attributed_appointments', ['slice' => 'all'], 8, null],
            ['scheduling.booking_attribution_evidence', ['slice' => 'all', 'evidence' => 'meta_click_id'], 4, null],

            ['scheduling.landing_sessions', [...$campaign, 'traffic_class' => 'likely_human'], 20, null],
            ['scheduling.funnel_sessions', [...$campaign, 'traffic_class' => 'likely_human', 'step' => 'time_selected'], 10, null],
            ['scheduling.funnel_sessions', [...$campaign, 'traffic_class' => 'likely_human', 'step' => 'submit_attempt'], 6, null],
            ['scheduling.booking_conversion', [...$campaign, 'traffic_class' => 'likely_human'], 4, 20],
            ['scheduling.attributed_appointments', $campaign, 4, null],
            ['scheduling.booking_attribution_evidence', [...$campaign, 'evidence' => 'meta_click_id'], 4, null],

            ['scheduling.landing_sessions', ['slice' => 'service', 'service_key' => 'strategy-call', 'traffic_class' => 'likely_human'], 40, null],
            ['scheduling.funnel_sessions', ['slice' => 'service', 'service_key' => 'strategy-call', 'traffic_class' => 'likely_human', 'step' => 'time_selected'], 18, null],
            ['scheduling.booking_conversion', ['slice' => 'service', 'service_key' => 'strategy-call', 'traffic_class' => 'likely_human'], 8, 40],
            ['scheduling.validation_failure_rate', ['slice' => 'service', 'service_key' => 'strategy-call', 'traffic_class' => 'likely_human'], 3, 12],
            ['scheduling.public_appointments', ['slice' => 'service', 'service_id' => '7', 'service_key' => 'strategy-call'], 8, null],
        ] as [$metricKey, $dimensions, $numerator, $denominator]) {
            $this->createMetric(
                date: $date,
                metricKey: $metricKey,
                dimensions: $dimensions,
                numerator: $numerator,
                denominator: $denominator,
            );
        }
    }

    private function seedReportMetrics(): void
    {
        $date = '2026-08-16';

        foreach ([
            ['webinar.landing_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human'], 80, null],
            ['webinar.landing_sessions', ['slice' => 'all', 'traffic_class' => 'likely_automated'], 15, null],
            ['webinar.landing_sessions', ['slice' => 'all', 'traffic_class' => 'unknown'], 5, null],
            ['webinar.traffic_share', ['slice' => 'all', 'traffic_class' => 'likely_human'], 80, 100],
            ['webinar.traffic_share', ['slice' => 'all', 'traffic_class' => 'likely_automated'], 15, 100],
            ['webinar.traffic_share', ['slice' => 'all', 'traffic_class' => 'unknown'], 5, 100],
            ['webinar.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'landing'], 80, null],
            ['webinar.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'cta_click'], 55, null],
            ['webinar.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'modal_open'], 50, null],
            ['webinar.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'form_start'], 40, null],
            ['webinar.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'submit_attempt'], 30, null],
            ['webinar.registration_conversion', ['slice' => 'all', 'traffic_class' => 'likely_human'], 12, 80],
            ['webinar.validation_failure_rate', ['slice' => 'all', 'traffic_class' => 'likely_human'], 6, 30],
            ['webinar.validation_failures', ['slice' => 'all', 'field_key' => 'email'], 4, null],
            ['webinar.local_registrations', ['slice' => 'all'], 12, null],
            ['webinar.attributed_registrations', ['slice' => 'all'], 12, null],
            ['webinar.registration_attribution_evidence', ['slice' => 'all', 'evidence' => 'meta_click_id'], 4, null],
            ['webinar.registration_correlation_coverage', ['slice' => 'all'], 12, 12],
            ['webinar.provider_completion', ['slice' => 'all'], 11, 12],
            ['webinar.confirmation_planning', ['slice' => 'all'], 11, 11],
            ['webinar.confirmation_delivery', ['slice' => 'all'], 10, 11],
            ['webinar.join_rate', ['slice' => 'all'], 7, 10],
            ['webinar.attendance_rate', ['slice' => 'all'], 5, 9],
            ['webinar.registration_finalization_outcomes', ['slice' => 'all', 'outcome' => 'completed'], 11, null],
            ['webinar.registration_finalization_outcomes', ['slice' => 'all', 'outcome' => 'failed', 'reason' => 'provider_permanent_failure'], 1, null],
            ['webinar.provider_outcomes', ['slice' => 'all', 'outcome' => 'succeeded'], 11, null],
            ['webinar.provider_outcomes', ['slice' => 'all', 'outcome' => 'permanent_failure'], 1, null],
            ['webinar.confirmation_terminal_outcomes', ['slice' => 'all', 'outcome' => 'sent'], 10, null],
            ['webinar.confirmation_terminal_outcomes', ['slice' => 'all', 'outcome' => 'failed'], 1, null],
            ['webinar.throttled_requests', ['slice' => 'all', 'reason' => 'rate_limited'], 2, null],
            ['webinar.bot_protection_results', ['slice' => 'all', 'outcome' => 'passed'], 90, null],

            ['webinar.landing_sessions', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed', 'traffic_class' => 'likely_human'], 20, null],
            ['webinar.landing_sessions', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed', 'traffic_class' => 'likely_automated'], 5, null],
            ['webinar.traffic_share', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed', 'traffic_class' => 'likely_human'], 20, 25],
            ['webinar.funnel_sessions', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed', 'traffic_class' => 'likely_human', 'step' => 'form_start'], 12, null],
            ['webinar.funnel_sessions', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed', 'traffic_class' => 'likely_human', 'step' => 'submit_attempt'], 10, null],
            ['webinar.registration_conversion', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed', 'traffic_class' => 'likely_human'], 5, 20],
            ['webinar.attributed_registrations', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed'], 5, null],
            ['webinar.registration_attribution_evidence', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed', 'evidence' => 'meta_click_id'], 4, null],
            ['webinar.validation_failure_rate', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'august_homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'first_time_buyers', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-100', 'external_group_id' => 'grp-200', 'external_creative_id' => 'ad-300', 'external_placement' => 'facebook_feed', 'traffic_class' => 'likely_human'], 2, 10],

            ['webinar.landing_sessions', ['slice' => 'path', 'path' => '/homebuyer-class', 'traffic_class' => 'likely_human'], 80, null],
            ['webinar.traffic_share', ['slice' => 'path', 'path' => '/homebuyer-class', 'traffic_class' => 'likely_human'], 80, 100],
            ['webinar.registration_conversion', ['slice' => 'path', 'path' => '/homebuyer-class', 'traffic_class' => 'likely_human'], 12, 80],

            ['webinar.landing_sessions', ['slice' => 'presentation', 'page_revision' => 'revision-1', 'presentation' => 'modal', 'traffic_class' => 'likely_human'], 80, null],
            ['webinar.traffic_share', ['slice' => 'presentation', 'page_revision' => 'revision-1', 'presentation' => 'modal', 'traffic_class' => 'likely_human'], 80, 100],
            ['webinar.registration_conversion', ['slice' => 'presentation', 'page_revision' => 'revision-1', 'presentation' => 'modal', 'traffic_class' => 'likely_human'], 12, 80],

            ['webinar.landing_sessions', ['slice' => 'device', 'device_class' => 'mobile', 'traffic_class' => 'likely_human'], 60, null],
            ['webinar.traffic_share', ['slice' => 'device', 'device_class' => 'mobile', 'traffic_class' => 'likely_human'], 60, 75],
            ['webinar.registration_conversion', ['slice' => 'device', 'device_class' => 'mobile', 'traffic_class' => 'likely_human'], 8, 60],

            ['webinar.local_registrations', ['slice' => 'series', 'series_id' => 1, 'series_slug' => 'homebuyer-class'], 12, null],
            ['webinar.provider_completion', ['slice' => 'series', 'series_id' => 1, 'series_slug' => 'homebuyer-class'], 11, 12],
            ['webinar.confirmation_delivery', ['slice' => 'series', 'series_id' => 1, 'series_slug' => 'homebuyer-class'], 10, 11],
        ] as [$metricKey, $dimensions, $numerator, $denominator]) {
            $this->createMetric(
                date: $date,
                metricKey: $metricKey,
                dimensions: $dimensions,
                numerator: $numerator,
                denominator: $denominator,
            );
        }
    }

    /**
     * @param array<string, scalar|null> $dimensions
     */
    private function createMetric(
        string $date,
        string $metricKey,
        array $dimensions,
        int $numerator,
        ?int $denominator,
    ): void {
        $canonical = array_filter(
            $dimensions,
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
        ksort($canonical);

        ReportingDailyMetric::query()->create([
            'metric_date' => $date,
            'metric_key' => $metricKey,
            'metric_version' => ProjectReportingDailyMetricsAction::METRIC_VERSION,
            'dimension_hash' => hash(
                'sha256',
                json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ),
            'dimensions' => $canonical,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'projected_through' => now(),
        ]);
    }
}