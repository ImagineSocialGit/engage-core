<?php

namespace Tests\Feature\Messaging;

use App\Integrations\Messaging\Email\Resend\ResendMessageEventWebhookHandler;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageEmailOpenSignal;
use App\Modules\Messaging\Providers\MessagingModuleServiceProvider;
use App\Modules\Messaging\ReadModels\MessagingEmailOpenFactContributor;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmailOpenEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'email-open-evidence-test');
        config()->set('services.resend.webhook_secret', 'resend-open-webhook-secret');
        config()->set('services.resend.webhook_timestamp_drift_seconds', 300);
        Carbon::setTestNow('2026-09-22 20:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_resend_open_event_records_compact_evidence_for_the_matching_email_delivery(): void
    {
        [$message, $attempt] = $this->resendMessage('email_open_1');

        $response = app(ResendMessageEventWebhookHandler::class)->handle(
            $this->signedResendRequest(
                payload: $this->openEvent(
                    providerMessageId: 'email_open_1',
                    occurredAt: '2026-09-22T19:45:00.000Z',
                ),
                eventId: 'evt_open_1',
            ),
        );

        $this->assertSame(204, $response->getStatusCode());

        $signal = ScheduledMessageEmailOpenSignal::query()->sole();

        $this->assertSame($message->getKey(), $signal->scheduled_message_id);
        $this->assertSame($attempt->getKey(), $signal->delivery_attempt_id);
        $this->assertSame('resend', $signal->provider);
        $this->assertSame('email_open_1', $signal->provider_message_id);
        $this->assertSame(1, $signal->occurrence_count);
        $this->assertTrue($signal->first_occurred_at->equalTo(
            CarbonImmutable::parse('2026-09-22 19:45:00 UTC'),
        ));
        $this->assertTrue($signal->last_occurred_at->equalTo(
            CarbonImmutable::parse('2026-09-22 19:45:00 UTC'),
        ));
    }

    public function test_provider_event_replay_is_idempotent_while_distinct_open_events_increment_the_signal(): void
    {
        $this->resendMessage('email_open_repeat');
        $handler = app(ResendMessageEventWebhookHandler::class);

        $first = $this->signedResendRequest(
            payload: $this->openEvent(
                providerMessageId: 'email_open_repeat',
                occurredAt: '2026-09-22T19:40:00.000Z',
            ),
            eventId: 'evt_open_repeat_1',
        );

        $this->assertSame(204, $handler->handle($first)->getStatusCode());
        $this->assertSame(204, $handler->handle($first)->getStatusCode());

        $this->assertSame(
            1,
            ScheduledMessageEmailOpenSignal::query()->sole()->occurrence_count,
        );

        $second = $this->signedResendRequest(
            payload: $this->openEvent(
                providerMessageId: 'email_open_repeat',
                occurredAt: '2026-09-22T19:55:00.000Z',
            ),
            eventId: 'evt_open_repeat_2',
        );

        $this->assertSame(204, $handler->handle($second)->getStatusCode());

        $signal = ScheduledMessageEmailOpenSignal::query()->sole();

        $this->assertSame(2, $signal->occurrence_count);
        $this->assertTrue($signal->first_occurred_at->equalTo(
            CarbonImmutable::parse('2026-09-22 19:40:00 UTC'),
        ));
        $this->assertTrue($signal->last_occurred_at->equalTo(
            CarbonImmutable::parse('2026-09-22 19:55:00 UTC'),
        ));
    }

    public function test_unmatched_or_non_email_provider_message_does_not_create_open_evidence(): void
    {
        $this->resendMessage('known_email_message');

        $handler = app(ResendMessageEventWebhookHandler::class);
        $handler->handle($this->signedResendRequest(
            payload: $this->openEvent('missing_email_message'),
            eventId: 'evt_open_missing',
        ));

        $sms = ScheduledMessage::factory()->forContact()->sms()->sent()->create();
        $smsAttempt = $sms->deliveryAttempts()->sole();
        $smsAttempt->forceFill([
            'provider' => 'resend',
            'provider_message_id' => 'not_really_email',
        ])->save();

        $handler->handle($this->signedResendRequest(
            payload: $this->openEvent('not_really_email'),
            eventId: 'evt_open_non_email',
        ));

        $this->assertDatabaseCount('scheduled_message_email_open_signals', 0);
    }

    public function test_messaging_provider_registers_email_open_evidence_reporting_contributor(): void
    {
        $this->app->register(MessagingModuleServiceProvider::class, force: true);

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            iterator_to_array(
                $this->app->tagged('reporting.projection_fact_contributors'),
                false,
            ),
        );

        $this->assertContains(MessagingEmailOpenFactContributor::class, $classes);
    }

    public function test_reporting_contributor_exposes_weak_provider_evidence_without_recipient_pii(): void
    {
        [$message] = $this->resendMessage('email_open_reporting');
        $handler = app(ResendMessageEventWebhookHandler::class);

        $handler->handle($this->signedResendRequest(
            payload: $this->openEvent(
                providerMessageId: 'email_open_reporting',
                occurredAt: '2026-09-22T19:45:00.000Z',
            ),
            eventId: 'evt_open_reporting_1',
        ));

        $facts = collect(iterator_to_array(
            app(MessagingEmailOpenFactContributor::class)->facts(
                new ReportingProjectionWindow(
                    startsAt: CarbonImmutable::parse('2026-09-22 00:00:00 UTC'),
                    endsAt: CarbonImmutable::parse('2026-09-22 23:59:59 UTC'),
                ),
            ),
            false,
        ));

        $fact = $facts->sole();

        $this->assertSame(MessagingEmailOpenFactContributor::FACT_KEY, $fact->key);
        $this->assertSame((string) $message->getKey(), $fact->subjectId);
        $this->assertSame('resend', $fact->dimensions['provider']);
        $this->assertSame('weak', $fact->values['evidence_strength']);
        $this->assertSame('provider_tracking_pixel_load', $fact->values['evidence_kind']);
        $this->assertFalse($fact->values['human_read_confirmed']);
        $this->assertSame(1, $fact->values['occurrence_count']);
        $this->assertArrayNotHasKey('destination', $fact->dimensions);
        $this->assertArrayNotHasKey('email', $fact->dimensions);
        $this->assertArrayNotHasKey('destination', $fact->values);
        $this->assertArrayNotHasKey('email', $fact->values);
    }

    /** @return array{0: ScheduledMessage, 1: ScheduledMessageDeliveryAttempt} */
    private function resendMessage(string $providerMessageId): array
    {
        $message = ScheduledMessage::factory()
            ->forContact()
            ->email()
            ->sent()
            ->create();
        $message->forceFill([
            'send_at' => now()->subHour(),
        ])->save();

        $attempt = $message->deliveryAttempts()->sole();
        $attempt->forceFill([
            'claimed_at' => now()->subHour()->subSecond(),
            'lease_expires_at' => now()->subHour(),
            'provider_submission_started_at' => now()->subHour()->subSecond(),
            'completed_at' => now()->subHour(),
            'provider' => 'resend',
            'provider_message_id' => $providerMessageId,
        ])->save();

        return [$message, $attempt];
    }

    /** @return array<string, mixed> */
    private function openEvent(
        string $providerMessageId,
        string $occurredAt = '2026-09-22T19:45:00.000Z',
    ): array {
        return [
            'type' => 'email.opened',
            'created_at' => $occurredAt,
            'data' => [
                'email_id' => $providerMessageId,
                'to' => ['private@example.test'],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function signedResendRequest(array $payload, string $eventId): Request
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) Carbon::now()->getTimestamp();
        $secret = (string) config('services.resend.webhook_secret');
        $signature = base64_encode(hash_hmac(
            'sha256',
            $eventId.'.'.$timestamp.'.'.$body,
            $secret,
            true,
        ));

        $request = Request::create(
            uri: '/message-events/email/resend',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $request->headers->set('svix-id', $eventId);
        $request->headers->set('svix-timestamp', $timestamp);
        $request->headers->set('svix-signature', $signature);

        return $request;
    }
}