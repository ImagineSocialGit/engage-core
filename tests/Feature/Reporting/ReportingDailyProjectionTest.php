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