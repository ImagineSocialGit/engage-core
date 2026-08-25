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
            ->assertOk()
            ->assertSee('Webinar Registration')
            ->assertSee('See where visitors stop before they register')
            ->assertSee('What to look at first')
            ->assertSee('Investigate Landing visits → Form started first')
            ->assertSee('40 of 80 likely-human sessions did not reach the next measured stage (50.0%).')
            ->assertSee('Next step:')
            ->assertSee('Measurement coverage')
            ->assertSee('Ad attribution')
            ->assertSee('Exact acquisition comparison is available for matched IDs')
            ->assertSee('Observed landing')
            ->assertSee('Likely-human landing')
            ->assertSee('Refresh recent data')
            ->assertSee('What performs differently')
            ->assertSee('More comparable traffic is needed')
            ->assertSee('Comparison coverage')
            ->assertSee('Registration funnel')
            ->assertSee('Traffic quality')
            ->assertSee('Ad platform reports')
            ->assertSee('Exact stable-ID comparison available')
            ->assertSee('USD 100.00')
            ->assertSee('USD 20.00')
            ->assertSee('Campaign / source traffic')
            ->assertSee('cmp-100')
            ->assertSee('grp-200')
            ->assertSee('ad-300')
            ->assertSee('Facebook Feed')
            ->assertSee('Compare public-facing experience')
            ->assertSee('After registration')
            ->assertSee('Where validation failed')
            ->assertSee('Email')
            ->assertSee('Outcome details')
            ->assertSee('Technical collection signals')
            ->assertSee('80.0%')
            ->assertSee('15.0%')
            ->assertDontSee('webinar.registration_conversion')
            ->assertDontSee('likely_human')
            ->assertDontSee('utm_source')
            ->assertDontSee('dimension_hash');
    }

    public function test_reporting_workspace_does_not_invent_a_funnel_problem_when_all_likely_human_sessions_register(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 20:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        foreach ([
            ['webinar.landing_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human'], 1, null],
            ['webinar.landing_sessions', ['slice' => 'all', 'traffic_class' => 'unknown'], 2, null],
            ['webinar.traffic_share', ['slice' => 'all', 'traffic_class' => 'likely_human'], 1, 3],
            ['webinar.traffic_share', ['slice' => 'all', 'traffic_class' => 'likely_automated'], 0, 3],
            ['webinar.traffic_share', ['slice' => 'all', 'traffic_class' => 'unknown'], 2, 3],
            ['webinar.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'landing'], 1, null],
            ['webinar.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'form_start'], 1, null],
            ['webinar.funnel_sessions', ['slice' => 'all', 'traffic_class' => 'likely_human', 'step' => 'submit_attempt'], 1, null],
            ['webinar.registration_conversion', ['slice' => 'all', 'traffic_class' => 'likely_human'], 1, 1],
            ['webinar.local_registrations', ['slice' => 'all'], 1, null],
            ['webinar.registration_correlation_coverage', ['slice' => 'all'], 1, 1],
        ] as [$metricKey, $dimensions, $numerator, $denominator]) {
            $this->createMetric(
                date: '2026-08-16',
                metricKey: $metricKey,
                dimensions: $dimensions,
                numerator: $numerator,
                denominator: $denominator,
            );
        }

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/reporting?days=30')
            ->assertOk()
            ->assertSee('No measured pre-registration loss is visible yet')
            ->assertSee('All 1 likely-human landing sessions reached a correlated registration in this period.')
            ->assertSee('The primary funnel uses 1 likely-human landing sessions out of 3 observed landing sessions after bounded traffic-classification calibration.')
            ->assertSee('2 observed landing sessions remain unknown and are intentionally excluded from conversion.')
            ->assertSee('No ad-platform report is attached to this view yet');
    }

    public function test_reporting_workspace_surfaces_directional_comparisons_only_after_sample_guardrails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 20:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $date = '2026-08-16';

        foreach ([
            ['webinar.registration_conversion', ['slice' => 'device', 'device_class' => 'mobile', 'traffic_class' => 'likely_human'], 8, 60],
            ['webinar.registration_conversion', ['slice' => 'device', 'device_class' => 'desktop', 'traffic_class' => 'likely_human'], 12, 40],
            ['webinar.registration_conversion', ['slice' => 'device', 'device_class' => 'tablet', 'traffic_class' => 'likely_human'], 5, 5],

            ['webinar.registration_conversion', ['slice' => 'presentation', 'page_revision' => 'revision-1', 'presentation' => 'modal', 'traffic_class' => 'likely_human'], 6, 40],
            ['webinar.registration_conversion', ['slice' => 'presentation', 'page_revision' => 'revision-2', 'presentation' => 'inline', 'traffic_class' => 'likely_human'], 14, 40],

            ['webinar.registration_conversion', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'homebuyer', 'utm_content' => 'creative_a', 'utm_term' => 'set_a', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-1', 'external_group_id' => 'grp-1', 'external_creative_id' => 'ad-1', 'external_placement' => 'facebook_feed', 'traffic_class' => 'likely_human'], 5, 30],
            ['webinar.registration_conversion', ['slice' => 'campaign', 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'homebuyer', 'utm_content' => 'creative_b', 'utm_term' => 'set_b', 'external_platform' => 'meta', 'external_campaign_id' => 'cmp-1', 'external_group_id' => 'grp-2', 'external_creative_id' => 'ad-2', 'external_placement' => 'instagram_reels', 'traffic_class' => 'likely_human'], 12, 30],
        ] as [$metricKey, $dimensions, $numerator, $denominator]) {
            $this->createMetric(
                date: $date,
                metricKey: $metricKey,
                dimensions: $dimensions,
                numerator: $numerator,
                denominator: $denominator,
            );
        }

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/reporting?days=30')
            ->assertOk()
            ->assertSee('What performs differently')
            ->assertSee('Device class')
            ->assertSee('Desktop is converting higher than Mobile')
            ->assertSee('30.0% (12/40) versus Mobile: 13.3% (8/60), a 16.7 percentage-point gap.')
            ->assertSee('Page / registration presentation')
            ->assertSee('Inline · Revision-2 is converting higher than Modal · Revision-1')
            ->assertSee('Creative / ad')
            ->assertSee('creative_b is converting higher than creative_a')
            ->assertSee('Placement')
            ->assertSee('Instagram Reels is converting higher than Facebook Feed')
            ->assertSee('2 of 3 variants eligible')
            ->assertDontSee('Tablet is converting higher than Desktop');
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
            ->assertOk()
            ->assertSee('30 days');

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

    public function test_workspace_exposes_classification_resolution_explanations(): void
    {
        $view = file_get_contents(resource_path('views/crm/reporting/index.blade.php'));
        $service = file_get_contents(app_path('Modules/Reporting/Services/ReportingWorkspaceReadService.php'));

        $this->assertIsString($view);
        $this->assertIsString($service);
        $this->assertStringContainsString('Unknown sessions resolved for analysis', $view);
        $this->assertStringContainsString('Why traffic is still unknown', $view);
        $this->assertStringContainsString('classification_resolution', $service);
        $this->assertStringContainsString('mobile_webview_evidence', $service);
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