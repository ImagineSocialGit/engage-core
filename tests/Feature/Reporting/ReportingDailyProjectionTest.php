<?php

namespace Tests\Feature\Reporting;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingProjectionCheckpoint;
use App\Modules\Reporting\Models\ReportingSession;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\ReadModels\WebinarFunnelFactContributor;
use App\Support\Reporting\ReportingProjectionFactRegistry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportingDailyProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_projection_combines_human_browser_funnel_with_authoritative_webinar_outcomes_idempotently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 18:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $session = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'session-1'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(2),
            'absolute_expires_at' => now()->addHour(),
            'landing_path' => '/homebuyer-class',
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
            'click_id_hashes' => [
                'meta_fbclid' => hash('sha256', 'test-meta-click'),
            ],
            'traffic_class' => 'likely_human',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 1,
            'classification_reasons' => ['browser_family_recognized'],
            'device_class' => 'mobile',
            'browser_family' => 'Chrome',
            'os_family' => 'Android',
        ]);

        $this->observation(
            session: $session,
            eventKey: 'webinar.page.view',
            eventId: (string) Str::uuid(),
            occurredAt: now()->subHours(3),
            trafficClass: 'likely_human',
            properties: [
                'page_revision' => 'revision-1',
                'presentation' => 'modal',
            ],
        );

        $submitAttemptId = (string) Str::uuid();
        $this->observation(
            session: $session,
            eventKey: 'webinar.form.submit_attempt',
            eventId: $submitAttemptId,
            occurredAt: now()->subHours(2),
            trafficClass: 'unknown',
            properties: [
                'page_revision' => 'revision-1',
                'presentation' => 'modal',
            ],
        );
        $this->observation(
            session: $session,
            eventKey: 'webinar.form.validation_failed',
            eventId: (string) Str::uuid(),
            occurredAt: now()->subHours(2)->addMinute(),
            trafficClass: 'likely_human',
            properties: [
                'page_revision' => 'revision-1',
                'presentation' => 'modal',
                'field_keys' => ['email'],
            ],
        );

        $series = WebinarSeries::factory()->create([
            'slug' => 'homebuyer-class',
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'slug' => 'homebuyer-class-august',
            'external_id' => 'zoom-2001',
            'platform' => 'zoom',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(10),
            'meta' => [
                'normalized' => [
                    'post_event' => [
                        'attendance_ready' => true,
                    ],
                ],
            ],
        ]);
        $registration = WebinarRegistration::factory()->for($webinar)->create([
            'status' => 'attended',
            'source' => 'webinar_subdomain',
            'registered_at' => now()->subHours(2)->addMinutes(2),
            'attended_at' => now()->subMinutes(30),
            'meta' => [
                'public_submission_attempt_id' => $submitAttemptId,
                'accepted_channels' => [
                    'transactional' => ['email'],
                    'marketing' => [],
                ],
                'registration_finalization' => ['status' => 'completed'],
                'provider_sync' => ['status' => 'succeeded'],
                'join_interaction' => [
                    'source' => 'public_signed_post',
                    'first_confirmed_at' => now()->subMinutes(50)->toIso8601String(),
                ],
            ],
        ]);
        $registration->responses()->create([
            'question_key' => 'buying_timeline',
            'question_label' => 'When?',
            'question_type' => 'select',
            'answer_key' => 'within_3_months',
            'answer_label' => 'Within 3 months',
            'answer_text' => null,
            'definition_version' => '2026_08',
            'sort_order' => 10,
        ]);
        ScheduledMessage::factory()->sent()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $registry = new ReportingProjectionFactRegistry([
            app(WebinarFunnelFactContributor::class),
        ]);
        $projector = new ProjectReportingDailyMetricsAction($registry);
        $date = CarbonImmutable::now('America/Chicago')->startOfDay();

        $projector->handle($date, $date);
        $firstMetricCount = ReportingDailyMetric::query()->count();
        $projector->handle($date, $date);

        $this->assertSame(
            $firstMetricCount,
            ReportingDailyMetric::query()->count(),
        );
        $this->assertMetric(
            'webinar.registration_conversion',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
            1,
            1,
        );
        $this->assertMetric(
            'webinar.traffic_share',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
            1,
            1,
        );
        $this->assertMetric(
            'webinar.registration_conversion',
            [
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
                'traffic_class' => 'likely_human',
            ],
            1,
            1,
        );
        $this->assertMetric(
            'webinar.attributed_registrations',
            ['slice' => 'all'],
            1,
            null,
        );
        $this->assertMetric(
            'webinar.attributed_registrations',
            [
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
            ],
            1,
            null,
        );
        $this->assertMetric(
            'webinar.registration_attribution_evidence',
            ['slice' => 'all', 'evidence' => 'meta_click_id'],
            1,
            null,
        );
        $this->assertMetric(
            'webinar.validation_failure_rate',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
            1,
            1,
        );
        $this->assertMetric('webinar.provider_completion', ['slice' => 'all'], 1, 1);
        $this->assertMetric('webinar.confirmation_planning', ['slice' => 'all'], 1, 1);
        $this->assertMetric('webinar.confirmation_delivery', ['slice' => 'all'], 1, 1);
        $this->assertMetric('webinar.join_rate', ['slice' => 'all'], 1, 1);
        $this->assertMetric('webinar.attendance_rate', ['slice' => 'all'], 1, 1);
        $this->assertMetric(
            'webinar.question_answers',
            [
                'slice' => 'all',
                'question_key' => 'buying_timeline',
                'answer_key' => 'within_3_months',
                'definition_version' => '2026_08',
            ],
            1,
            null,
        );

        $checkpoint = ReportingProjectionCheckpoint::query()
            ->where('projector_key', ProjectReportingDailyMetricsAction::PROJECTOR_KEY)
            ->where('projector_version', ProjectReportingDailyMetricsAction::PROJECTOR_VERSION)
            ->sole();

        $this->assertSame($date->toDateString(), $checkpoint->cursor);
        $this->assertSame('America/Chicago', $checkpoint->meta['timezone']);
    }

    public function test_authoritative_registration_attribution_survives_unknown_traffic_and_does_not_require_same_day_landing_profile(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 18:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $session = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'unknown-paid-session'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => now()->subHours(2),
            'last_seen_at' => now()->subHour(),
            'absolute_expires_at' => now()->addHour(),
            'landing_path' => '/homebuyer-game-plan',
            'utm_source' => 'ig',
            'utm_medium' => 'paid',
            'utm_campaign' => 'homebuyer_game_plan',
            'external_platform' => 'meta',
            'external_campaign_id' => 'cmp-paid',
            'external_group_id' => 'grp-paid',
            'external_creative_id' => 'ad-paid',
            'external_placement' => 'instagram_feed',
            'click_id_hashes' => [
                'meta_fbclid' => hash('sha256', 'paid-click'),
            ],
            'traffic_class' => 'unknown',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 2,
            'classification_reasons' => ['user_agent_missing'],
            'device_class' => 'mobile',
            'os_family' => 'iOS',
        ]);

        $submitAttemptId = (string) Str::uuid();
        $this->observation(
            session: $session,
            eventKey: 'webinar.form.submit_attempt',
            eventId: $submitAttemptId,
            occurredAt: now()->subHour(),
            trafficClass: 'unknown',
            properties: [
                'page_revision' => 'rob-register-inline-v3',
                'presentation' => 'inline',
            ],
        );

        $series = WebinarSeries::factory()->create([
            'slug' => 'homebuyer-game-plan',
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'slug' => 'homebuyer-game-plan-september',
            'external_id' => 'zoom-paid',
            'platform' => 'zoom',
            'starts_at' => now()->addDays(5),
        ]);
        WebinarRegistration::factory()->for($webinar)->create([
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'registered_at' => now()->subMinutes(50),
            'meta' => [
                'public_submission_attempt_id' => $submitAttemptId,
                'registration_finalization' => ['status' => 'completed'],
                'provider_sync' => ['status' => 'succeeded'],
            ],
        ]);

        $projector = new ProjectReportingDailyMetricsAction(
            new ReportingProjectionFactRegistry([
                app(WebinarFunnelFactContributor::class),
            ]),
        );
        $date = CarbonImmutable::now('America/Chicago')->startOfDay();

        $projector->handle($date, $date);

        $campaign = [
            'slice' => 'campaign',
            'utm_source' => 'ig',
            'utm_medium' => 'paid',
            'utm_campaign' => 'homebuyer_game_plan',
            'external_platform' => 'meta',
            'external_campaign_id' => 'cmp-paid',
            'external_group_id' => 'grp-paid',
            'external_creative_id' => 'ad-paid',
            'external_placement' => 'instagram_feed',
        ];

        $this->assertMetric(
            'webinar.attributed_registrations',
            $campaign,
            1,
            null,
        );
        $this->assertMetric(
            'webinar.registration_attribution_evidence',
            [...$campaign, 'evidence' => 'meta_click_id'],
            1,
            null,
        );
        $this->assertFalse(
            ReportingDailyMetric::query()
                ->where('metric_key', 'webinar.registration_conversion')
                ->get()
                ->contains(fn (ReportingDailyMetric $metric): bool =>
                    data_get($metric->dimensions, 'slice') === 'campaign'
                ),
        );
    }

    public function test_authoritative_registration_attribution_uses_referrer_host_when_campaign_tags_are_absent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 18:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $session = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'referral-session'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => now()->subHour(),
            'last_seen_at' => now()->subMinutes(30),
            'absolute_expires_at' => now()->addHours(3),
            'landing_path' => '/homebuyer-game-plan',
            'referrer_host' => 'trusted-referral.example',
            'traffic_class' => 'likely_human',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 3,
            'classification_reasons' => ['browser_family_recognized'],
            'device_class' => 'mobile',
        ]);

        $submitAttemptId = (string) Str::uuid();
        $this->observation(
            session: $session,
            eventKey: 'webinar.form.submit_attempt',
            eventId: $submitAttemptId,
            occurredAt: now()->subMinutes(30),
            trafficClass: 'likely_human',
            properties: [
                'page_revision' => 'rob-register-inline-v3',
                'presentation' => 'inline',
            ],
        );

        $series = WebinarSeries::factory()->create([
            'slug' => 'homebuyer-game-plan',
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'slug' => 'homebuyer-game-plan-referral',
            'external_id' => 'zoom-referral',
            'platform' => 'zoom',
            'starts_at' => now()->addDays(5),
        ]);
        WebinarRegistration::factory()->for($webinar)->create([
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'registered_at' => now()->subMinutes(25),
            'meta' => [
                'public_submission_attempt_id' => $submitAttemptId,
                'registration_finalization' => ['status' => 'completed'],
                'provider_sync' => ['status' => 'succeeded'],
            ],
        ]);

        $projector = new ProjectReportingDailyMetricsAction(
            new ReportingProjectionFactRegistry([
                app(WebinarFunnelFactContributor::class),
            ]),
        );
        $date = CarbonImmutable::now('America/Chicago')->startOfDay();

        $projector->handle($date, $date);

        $referralMetric = ReportingDailyMetric::query()
            ->where('metric_key', 'webinar.attributed_registrations')
            ->get()
            ->first(fn (ReportingDailyMetric $metric): bool =>
                data_get($metric->dimensions, 'slice') === 'campaign'
                && data_get($metric->dimensions, 'referrer_host') === 'trusted-referral.example'
            );

        $this->assertNotNull($referralMetric);
        $this->assertSame(1, $referralMetric->numerator);
        $this->assertNull(data_get($referralMetric->dimensions, 'utm_source'));
    }

    public function test_projection_can_resolve_unknown_session_from_bounded_active_engagement_signal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 18:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $session = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'bounded-engagement-session'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => now()->subMinutes(20),
            'last_seen_at' => now()->subMinutes(10),
            'absolute_expires_at' => now()->addHours(3),
            'landing_path' => '/homebuyer-game-plan',
            'traffic_class' => 'unknown',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 3,
            'classification_reasons' => ['user_agent_unrecognized'],
        ]);

        $this->observation(
            session: $session,
            eventKey: 'webinar.page.view',
            eventId: (string) Str::uuid(),
            occurredAt: now()->subMinutes(20),
            trafficClass: 'unknown',
            properties: [
                'page_revision' => 'rob-register-inline-v3',
                'presentation' => 'inline',
            ],
        );
        $this->observation(
            session: $session,
            eventKey: 'webinar.engagement.signal',
            eventId: (string) Str::uuid(),
            occurredAt: now()->subMinutes(10),
            trafficClass: 'unknown',
            properties: [
                'page_revision' => 'rob-register-inline-v3',
                'presentation' => 'inline',
                'signal' => 'active_10s',
            ],
        );

        $projector = new ProjectReportingDailyMetricsAction(
            new ReportingProjectionFactRegistry([]),
        );
        $date = CarbonImmutable::now('America/Chicago')->startOfDay();

        $projector->handle($date, $date);

        $this->assertMetric(
            'webinar.traffic_classification_resolution',
            [
                'slice' => 'all',
                'recorded_traffic_class' => 'unknown',
                'effective_traffic_class' => 'likely_human',
                'reason' => 'active_time_evidence',
            ],
            1,
            null,
        );
        $this->assertMetric(
            'webinar.landing_sessions',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
            1,
            null,
        );
    }

    public function test_projection_calibrates_retained_unknown_mobile_webview_and_interaction_evidence_without_mutating_raw_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 18:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $mobileWebview = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'historical-mobile-webview'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(2),
            'absolute_expires_at' => now()->addHour(),
            'landing_path' => '/homebuyer-class',
            'utm_source' => 'ig',
            'utm_medium' => 'paid',
            'traffic_class' => 'unknown',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 2,
            'classification_reasons' => ['user_agent_unrecognized'],
            'device_class' => 'mobile',
            'browser_family' => null,
            'os_family' => 'iOS',
        ]);

        $this->observation(
            session: $mobileWebview,
            eventKey: 'webinar.page.view',
            eventId: (string) Str::uuid(),
            occurredAt: now()->subHours(3),
            trafficClass: 'unknown',
            properties: [
                'page_revision' => 'revision-1',
                'presentation' => 'inline',
            ],
        );

        $interaction = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'historical-interaction'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => now()->subHours(2),
            'last_seen_at' => now()->subHour(),
            'absolute_expires_at' => now()->addHour(),
            'landing_path' => '/homebuyer-class',
            'traffic_class' => 'unknown',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 2,
            'classification_reasons' => ['user_agent_unrecognized'],
            'device_class' => null,
            'browser_family' => null,
            'os_family' => null,
        ]);

        $this->observation(
            session: $interaction,
            eventKey: 'webinar.page.view',
            eventId: (string) Str::uuid(),
            occurredAt: now()->subHours(2),
            trafficClass: 'unknown',
            properties: [
                'page_revision' => 'revision-1',
                'presentation' => 'inline',
            ],
        );
        $this->observation(
            session: $interaction,
            eventKey: 'webinar.form.start',
            eventId: (string) Str::uuid(),
            occurredAt: now()->subHour(),
            trafficClass: 'unknown',
            properties: [
                'page_revision' => 'revision-1',
                'presentation' => 'inline',
            ],
        );

        $unresolved = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'historical-unresolved'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => now()->subMinutes(45),
            'last_seen_at' => now()->subMinutes(40),
            'absolute_expires_at' => now()->addHour(),
            'landing_path' => '/homebuyer-class',
            'traffic_class' => 'unknown',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 2,
            'classification_reasons' => ['user_agent_missing'],
        ]);

        $this->observation(
            session: $unresolved,
            eventKey: 'webinar.page.view',
            eventId: (string) Str::uuid(),
            occurredAt: now()->subMinutes(45),
            trafficClass: 'unknown',
            properties: [
                'page_revision' => 'revision-1',
                'presentation' => 'inline',
            ],
        );

        $registry = new ReportingProjectionFactRegistry([]);
        $projector = new ProjectReportingDailyMetricsAction($registry);
        $date = CarbonImmutable::now('America/Chicago')->startOfDay();

        $projector->handle($date, $date);

        $this->assertMetric(
            'webinar.landing_sessions',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
            2,
            null,
        );
        $this->assertMetric(
            'webinar.landing_sessions',
            ['slice' => 'all', 'traffic_class' => 'unknown'],
            1,
            null,
        );
        $this->assertMetric(
            'webinar.traffic_classification_resolution',
            [
                'slice' => 'all',
                'recorded_traffic_class' => 'unknown',
                'effective_traffic_class' => 'likely_human',
                'reason' => 'mobile_webview_evidence',
            ],
            1,
            null,
        );
        $this->assertMetric(
            'webinar.traffic_classification_resolution',
            [
                'slice' => 'all',
                'recorded_traffic_class' => 'unknown',
                'effective_traffic_class' => 'likely_human',
                'reason' => 'active_form_interaction',
            ],
            1,
            null,
        );
        $this->assertMetric(
            'webinar.traffic_classification_resolution',
            [
                'slice' => 'all',
                'recorded_traffic_class' => 'unknown',
                'effective_traffic_class' => 'unknown',
                'reason' => 'user_agent_missing',
            ],
            1,
            null,
        );

        $this->assertSame('unknown', $mobileWebview->refresh()->traffic_class);
        $this->assertSame('unknown', $interaction->refresh()->traffic_class);
    }

    /** @param array<string, mixed> $properties */
    private function observation(
        ReportingSession $session,
        string $eventKey,
        string $eventId,
        mixed $occurredAt,
        string $trafficClass,
        array $properties,
    ): ReportingObservation {
        return ReportingObservation::query()->create([
            'event_id' => $eventId,
            'payload_hash' => hash('sha256', $eventId),
            'reporting_session_id' => $session->getKey(),
            'event_key' => $eventKey,
            'event_version' => 1,
            'source' => 'browser',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'path' => '/homebuyer-class',
            'utm_source' => 'meta',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'august_homebuyer',
            'utm_content' => 'creative_a',
            'traffic_class' => $trafficClass,
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 1,
            'classification_reasons' => ['browser_family_recognized'],
            'device_class' => 'mobile',
            'browser_family' => 'Chrome',
            'os_family' => 'Android',
            'properties' => $properties,
        ]);
    }

    /** @param array<string, scalar|null> $dimensions */
    private function assertMetric(
        string $metricKey,
        array $dimensions,
        int $numerator,
        ?int $denominator,
    ): void {
        $metric = ReportingDailyMetric::query()
            ->where('metric_key', $metricKey)
            ->get()
            ->first(fn (ReportingDailyMetric $metric): bool =>
                $metric->dimensions == $dimensions
            );

        $this->assertNotNull(
            $metric,
            'Missing metric ['.$metricKey.'] with dimensions '.json_encode($dimensions),
        );
        $this->assertSame($numerator, $metric->numerator);
        $this->assertSame($denominator, $metric->denominator);
    }
}