<?php

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingProjectionCheckpoint;
use App\Modules\Reporting\Models\ReportingSession;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\ReadModels\SchedulingBookingFunnelFactContributor;
use App\Support\Reporting\ReportingProjectionFactRegistry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingDailyProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_combines_public_booking_funnel_attribution_and_authoritative_appointment_idempotently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 18:00:00 UTC'));
        config(['client.timezone' => 'America/Chicago']);

        $session = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'scheduling-session'),
            'host' => 'booking.example.test',
            'surface' => 'scheduling_public_booking',
            'started_at' => now()->subHours(2),
            'last_seen_at' => now()->subHour(),
            'absolute_expires_at' => now()->addHours(2),
            'landing_path' => '/',
            'utm_source' => 'meta',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'strategy_calls',
            'utm_content' => 'creative_a',
            'external_platform' => 'meta',
            'external_campaign_id' => 'cmp-scheduling',
            'external_group_id' => 'grp-scheduling',
            'external_creative_id' => 'ad-scheduling',
            'external_placement' => 'facebook_feed',
            'click_id_hashes' => [
                'meta_fbclid' => hash('sha256', 'scheduling-click'),
            ],
            'traffic_class' => 'likely_human',
            'classifier_key' => 'browser_request_signals',
            'classifier_version' => 1,
            'classification_reasons' => ['browser_family_recognized'],
            'device_class' => 'mobile',
            'browser_family' => 'Chrome',
            'os_family' => 'Android',
        ]);

        $common = [
            'page_revision' => 'scheduling-public-v1',
            'state' => 'availability',
            'service_key' => 'strategy-call',
        ];
        $this->observation($session, 'scheduling.booking.page_view', $common, now()->subHours(2));
        $this->observation($session, 'scheduling.booking.service_selected', $common, now()->subMinutes(110));
        $this->observation($session, 'scheduling.booking.availability_viewed', [
            ...$common,
            'availability_state' => 'available',
        ], now()->subMinutes(100));
        $this->observation($session, 'scheduling.booking.time_selected', [
            ...$common,
            'day_period' => 'afternoon',
        ], now()->subMinutes(90));
        $this->observation($session, 'scheduling.booking.verification_requested', [
            ...$common,
            'channel' => 'email',
        ], now()->subMinutes(80));
        $this->observation($session, 'scheduling.booking.verification_completed', [
            ...$common,
            'channel' => 'email',
        ], now()->subMinutes(75));
        $this->observation($session, 'scheduling.booking.details_started', $common, now()->subMinutes(70));

        $submitAttemptId = (string) Str::uuid();
        $this->observation(
            session: $session,
            eventKey: 'scheduling.booking.submit_attempt',
            properties: $common,
            occurredAt: now()->subMinutes(60),
            eventId: $submitAttemptId,
        );
        $this->observation($session, 'scheduling.booking.validation_failed', [
            ...$common,
            'field_keys' => ['first_name'],
        ], now()->subMinutes(59));

        $service = BookableService::factory()->create([
            'key' => 'strategy-call',
            'requires_confirmation' => false,
        ]);
        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'source' => 'public_booking',
            'status' => Appointment::STATUS_SCHEDULED,
            'created_at' => now()->subMinutes(55),
            'meta' => [
                'reporting' => [
                    'public_submission_attempt_id' => $submitAttemptId,
                ],
            ],
        ]);

        $projector = new ProjectReportingDailyMetricsAction(
            new ReportingProjectionFactRegistry([
                app(SchedulingBookingFunnelFactContributor::class),
            ]),
        );
        $date = CarbonImmutable::now('America/Chicago')->startOfDay();

        $projector->handle($date, $date);
        $firstCount = ReportingDailyMetric::query()->count();
        $projector->handle($date, $date);

        $this->assertSame($firstCount, ReportingDailyMetric::query()->count());
        $this->assertMetric(
            'scheduling.landing_sessions',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
            1,
            null,
        );

        foreach ([
            'catalog',
            'service_selected',
            'availability_viewed',
            'time_selected',
            'details_started',
            'submit_attempt',
        ] as $step) {
            $this->assertMetric(
                'scheduling.funnel_sessions',
                [
                    'slice' => 'all',
                    'step' => $step,
                    'traffic_class' => 'likely_human',
                ],
                1,
                null,
            );
        }

        $this->assertMetric(
            'scheduling.booking_conversion',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
            1,
            1,
        );
        $this->assertMetric(
            'scheduling.validation_failure_rate',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
            1,
            1,
        );
        $this->assertMetric(
            'scheduling.validation_failures',
            ['field_key' => 'first_name', 'slice' => 'all'],
            1,
            null,
        );
        $this->assertMetric(
            'scheduling.availability_outcomes',
            ['outcome' => 'available', 'slice' => 'all'],
            1,
            null,
        );
        $this->assertMetric(
            'scheduling.verification_channels',
            ['channel' => 'email', 'slice' => 'all', 'stage' => 'completed'],
            1,
            null,
        );
        $this->assertMetric(
            'scheduling.public_appointments',
            ['slice' => 'all'],
            1,
            null,
        );
        $this->assertMetric(
            'scheduling.booking_correlation_coverage',
            ['slice' => 'all'],
            1,
            1,
        );
        $this->assertMetric(
            'scheduling.attributed_appointments',
            ['slice' => 'all'],
            1,
            null,
        );
        $this->assertMetric(
            'scheduling.booking_attribution_evidence',
            ['evidence' => 'meta_click_id', 'slice' => 'all'],
            1,
            null,
        );
        $this->assertMetric(
            'scheduling.public_appointments',
            [
                'service_id' => (string) $service->id,
                'service_key' => 'strategy-call',
                'slice' => 'service',
            ],
            1,
            null,
        );

        $campaign = [
            'external_campaign_id' => 'cmp-scheduling',
            'external_creative_id' => 'ad-scheduling',
            'external_group_id' => 'grp-scheduling',
            'external_placement' => 'facebook_feed',
            'external_platform' => 'meta',
            'slice' => 'campaign',
            'utm_campaign' => 'strategy_calls',
            'utm_content' => 'creative_a',
            'utm_medium' => 'paid_social',
            'utm_source' => 'meta',
        ];
        $this->assertMetric(
            'scheduling.booking_conversion',
            [...$campaign, 'traffic_class' => 'likely_human'],
            1,
            1,
        );
        $this->assertMetric(
            'scheduling.attributed_appointments',
            $campaign,
            1,
            null,
        );

        $this->assertFalse(
            ReportingDailyMetric::query()
                ->where('metric_key', 'like', 'scheduling.%')
                ->get()
                ->contains(fn (ReportingDailyMetric $metric): bool =>
                    array_key_exists('location_type', $metric->dimensions ?? [])
                ),
        );

        $this->assertDatabaseHas('reporting_projection_checkpoints', [
            'projector_key' => ProjectReportingDailyMetricsAction::PROJECTOR_KEY,
            'projector_version' => ProjectReportingDailyMetricsAction::PROJECTOR_VERSION,
        ]);
    }

    /** @param array<string, mixed> $properties */
    private function observation(
        ReportingSession $session,
        string $eventKey,
        array $properties,
        mixed $occurredAt,
        ?string $eventId = null,
    ): ReportingObservation {
        $eventId ??= (string) Str::uuid();

        return ReportingObservation::query()->create([
            'event_id' => $eventId,
            'payload_hash' => hash('sha256', $eventId),
            'reporting_session_id' => $session->getKey(),
            'event_key' => $eventKey,
            'event_version' => 1,
            'source' => 'browser',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'host' => 'booking.example.test',
            'surface' => 'scheduling_public_booking',
            'path' => '/services/strategy-call',
            'utm_source' => 'meta',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'strategy_calls',
            'utm_content' => 'creative_a',
            'traffic_class' => 'likely_human',
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
            ->first(fn (ReportingDailyMetric $candidate): bool =>
                $candidate->dimensions == $dimensions
            );

        $this->assertNotNull(
            $metric,
            'Missing metric ['.$metricKey.'] with dimensions '.json_encode($dimensions),
        );
        $this->assertSame($numerator, $metric->numerator);
        $this->assertSame($denominator, $metric->denominator);
    }
}