<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageComponent;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\ReadModels\WebinarFunnelFactContributor;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebinarFunnelFactContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_webinar_read_model_exposes_only_bounded_authoritative_funnel_facts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 18:00:00 UTC'));

        $series = WebinarSeries::factory()->create([
            'slug' => 'homebuyer-class',
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'slug' => 'homebuyer-class-august',
            'external_id' => 'zoom-1001',
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
        $submissionAttemptId = (string) Str::uuid();
        $registration = WebinarRegistration::factory()->for($webinar)->create([
            'status' => 'attended',
            'source' => 'webinar_subdomain',
            'registered_at' => now()->subHours(2),
            'attended_at' => now()->subMinutes(30),
            'meta' => [
                'request_ip' => '203.0.113.42',
                'user_agent' => 'Sensitive Browser String',
                'public_submission_attempt_id' => $submissionAttemptId,
                'accepted_channels' => [
                    'transactional' => ['email'],
                    'marketing' => [],
                ],
                'registration_finalization' => [
                    'status' => 'completed',
                    'completion_reason' => 'registration_messages_planned',
                ],
                'provider_sync' => [
                    'status' => 'succeeded',
                ],
                'join_interaction' => [
                    'source' => 'public_signed_post',
                    'first_confirmed_at' => now()->subMinutes(50)->toIso8601String(),
                ],
            ],
        ]);

        $registration->responses()->create([
            'question_key' => 'buying_timeline',
            'question_label' => 'When are you planning to buy?',
            'question_type' => 'select',
            'answer_key' => 'within_3_months',
            'answer_label' => 'Within 3 months',
            'answer_text' => 'Sensitive free text must never be projected.',
            'definition_version' => '2026_08',
            'sort_order' => 10,
        ]);

        $confirmationCarrierTemplate = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinar.reporting_confirmation_carrier',
            'name' => 'Reporting Confirmation Carrier Fixture',
            'description' => null,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'source_version' => '1',
            'is_customized' => false,
            'customized_at' => null,
        ]);
        $confirmationCarrierVersion = app(
            PublishMessageTemplateVersionAction::class,
        )->handle(
            messageTemplate: $confirmationCarrierTemplate,
            payload: [
                'subject' => 'Confirmation carrier',
                'body' => 'Fixture.',
            ],
        );
        $confirmationCarrier = ScheduledMessage::factory()->sent()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'message_template_version_id' => $confirmationCarrierVersion->getKey(),
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder',
        ]);

        ScheduledMessageComponent::query()->create([
            'scheduled_message_id' => $confirmationCarrier->getKey(),
            'message_template_version_id' => $confirmationCarrierVersion->getKey(),
            'role' => 'registration_confirmation',
            'intent_key' => 'webinar.registration.confirmation',
            'message_consent_id' => null,
            'sort_order' => 10,
            'placement_key' => null,
        ]);

        $facts = collect(iterator_to_array(
            app(WebinarFunnelFactContributor::class)->facts(
                new ReportingProjectionWindow(
                    startsAt: CarbonImmutable::now('UTC')->startOfDay(),
                    endsAt: CarbonImmutable::now('UTC')->endOfDay(),
                ),
            ),
            false,
        ));

        $registrationFact = $facts->firstWhere(
            'key',
            WebinarFunnelFactContributor::FACT_KEY,
        );
        $questionFact = $facts->firstWhere(
            'key',
            WebinarFunnelFactContributor::QUESTION_FACT_KEY,
        );

        $this->assertNotNull($registrationFact);
        $this->assertSame(strtolower($submissionAttemptId), $registrationFact->correlationId);
        $this->assertSame('homebuyer-class', $registrationFact->dimensions['series_slug']);
        $this->assertSame('zoom', $registrationFact->dimensions['provider']);
        $this->assertSame('completed', $registrationFact->values['finalization_status']);
        $this->assertSame(
            'registration_messages_planned',
            $registrationFact->values['finalization_reason'],
        );
        $this->assertSame('succeeded', $registrationFact->values['provider_sync_status']);
        $this->assertTrue($registrationFact->values['confirmation_eligible']);
        $this->assertSame(1, $registrationFact->values['confirmation_planned_count']);
        $this->assertSame(1, $registrationFact->values['confirmation_sent_count']);
        $this->assertTrue($registrationFact->values['join_confirmed']);
        $this->assertTrue($registrationFact->values['attendance_finalized']);
        $this->assertSame('attended', $registrationFact->values['attendance_status']);

        $this->assertNotNull($questionFact);
        $this->assertEquals([
            'question_key' => 'buying_timeline',
            'answer_key' => 'within_3_months',
            'definition_version' => '2026_08',
        ], $questionFact->values);

        $encoded = json_encode($facts->map(fn ($fact): array => [
            'dimensions' => $fact->dimensions,
            'values' => $fact->values,
        ])->all(), JSON_THROW_ON_ERROR);

        foreach ([
            '203.0.113.42',
            'Sensitive Browser String',
            'Sensitive free text must never be projected.',
            'When are you planning to buy?',
            'Within 3 months',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }
    public function test_provider_failure_is_not_misreported_as_confirmation_planning_failure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 18:00:00 UTC'));

        $webinar = Webinar::factory()->create([
            'external_id' => 'zoom-2002',
            'platform' => 'zoom',
            'starts_at' => now()->addDay(),
        ]);
        $registration = WebinarRegistration::factory()->for($webinar)->create([
            'source' => 'webinar_subdomain',
            'registered_at' => now()->subHour(),
            'meta' => [
                'accepted_channels' => [
                    'transactional' => ['email'],
                    'marketing' => [],
                ],
                'registration_finalization' => [
                    'status' => 'failed',
                    'failure_reason' => 'provider_permanent_failure',
                ],
                'provider_sync' => [
                    'status' => 'permanent_failure',
                    'failure_reason' => 'provider_validation_rejected',
                ],
            ],
        ]);

        $facts = collect(iterator_to_array(
            app(WebinarFunnelFactContributor::class)->facts(
                new ReportingProjectionWindow(
                    startsAt: CarbonImmutable::now('UTC')->startOfDay(),
                    endsAt: CarbonImmutable::now('UTC')->endOfDay(),
                ),
            ),
            false,
        ));
        $fact = $facts->first(fn ($fact): bool =>
            $fact->key === WebinarFunnelFactContributor::FACT_KEY
                && $fact->subjectId === (string) $registration->getKey()
        );

        $this->assertNotNull($fact);
        $this->assertSame('permanent_failure', $fact->values['provider_sync_status']);
        $this->assertSame('provider_permanent_failure', $fact->values['finalization_reason']);
        $this->assertFalse($fact->values['confirmation_eligible']);
        $this->assertFalse($fact->values['confirmation_planned']);
        $this->assertSame(0, $fact->values['confirmation_planned_count']);
    }

}