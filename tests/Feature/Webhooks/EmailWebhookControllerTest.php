<?php

namespace Tests\Feature\Webhooks;

use App\Modules\InboundMessaging\Actions\Email\HandleInboundEmailWebhookAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EmailWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'client.key' => 'test-client',
            'services.resend.webhook_secret' => 'test-secret',
            'services.resend.webhook_timestamp_drift_seconds' => 300,
        ]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $event = $this->event(type: 'email.bounced');

        $this
            ->postResendWebhook(
                event: $event,
                eventId: 'evt_invalid_signature',
                signature: 'v1,invalid-signature',
            )
            ->assertForbidden();

        $this->assertDatabaseCount('message_suppressions', 0);
        $this->assertDatabaseCount('webhook_inbox_receipts', 0);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $event = $this->event(type: 'email.bounced');

        $this
            ->postResendWebhook(
                event: $event,
                eventId: 'evt_stale_timestamp',
                timestamp: now()->subMinutes(10)->timestamp,
            )
            ->assertForbidden();

        $this->assertDatabaseCount('message_suppressions', 0);
        $this->assertDatabaseCount('webhook_inbox_receipts', 0);
    }

    public function test_valid_bounce_event_creates_email_suppression_and_completed_receipt(): void
    {
        $event = $this->event(
            type: 'email.bounced',
            email: 'Person@Example.com',
        );

        $this
            ->postResendWebhook(
                event: $event,
                eventId: 'evt_bounce_1',
            )
            ->assertNoContent();

        $this->assertDatabaseHas('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'person@example.com',
            'reason' => MessageSuppression::REASON_BOUNCE,
            'provider' => MessageSuppression::PROVIDER_RESEND,
            'source_event_id' => 'evt_bounce_1',
            'released_at' => null,
        ]);

        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'client_key' => 'test-client',
            'provider' => MessageSuppression::PROVIDER_RESEND,
            'provider_event_id' => 'evt_bounce_1',
            'event_type' => 'email.bounced',
            'status' => WebhookInboxReceipt::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
    }

    public function test_completed_replay_returns_existing_outcome_without_duplicate_side_effects(): void
    {
        $event = $this->event(type: 'email.bounced');
        $eventId = 'evt_replay_1';
        $timestamp = time();

        $this
            ->postResendWebhook(
                event: $event,
                eventId: $eventId,
                timestamp: $timestamp,
            )
            ->assertNoContent();

        $this
            ->postResendWebhook(
                event: $event,
                eventId: $eventId,
                timestamp: $timestamp,
            )
            ->assertNoContent();

        $this->assertDatabaseCount('message_suppressions', 1);
        $this->assertDatabaseCount('webhook_inbox_receipts', 1);
        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'provider_event_id' => $eventId,
            'status' => WebhookInboxReceipt::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
    }

    public function test_failed_processing_is_recorded_and_same_event_can_resume(): void
    {
        $calls = 0;
        $action = Mockery::mock(HandleInboundEmailWebhookAction::class);
        $action->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function () use (&$calls): void {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('Simulated Resend processing failure.');
                }
            });

        app()->instance(HandleInboundEmailWebhookAction::class, $action);

        $event = $this->event(type: 'email.bounced');
        $eventId = 'evt_retryable_failure_1';
        $timestamp = time();

        $this->withoutExceptionHandling();

        try {
            $this->postResendWebhook(
                event: $event,
                eventId: $eventId,
                timestamp: $timestamp,
            );

            $this->fail('Expected the simulated processing failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated Resend processing failure.',
                $exception->getMessage(),
            );
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'provider_event_id' => $eventId,
            'status' => WebhookInboxReceipt::STATUS_RETRYABLE_FAILED,
            'attempts' => 1,
        ]);

        $this
            ->postResendWebhook(
                event: $event,
                eventId: $eventId,
                timestamp: $timestamp,
            )
            ->assertNoContent();

        $receipt = WebhookInboxReceipt::query()->sole();

        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $receipt->status);
        $this->assertSame(2, $receipt->attempts);
        $this->assertNotNull($receipt->completed_at);
        $this->assertNull($receipt->failed_at);
        $this->assertNull($receipt->last_error);
    }

    public function test_malformed_json_is_rejected_before_a_receipt_is_created(): void
    {
        $body = '{"type": "email.bounced",';
        $eventId = 'evt_bad_json';
        $timestamp = time();

        $this
            ->call(
                method: 'POST',
                uri: route('webhooks.email', ['provider' => 'resend']),
                server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_SVIX_ID' => $eventId,
                    'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
                    'HTTP_SVIX_SIGNATURE' => $this->signature($eventId, $timestamp, $body),
                ],
                content: $body,
            )
            ->assertBadRequest();

        $this->assertDatabaseCount('message_suppressions', 0);
        $this->assertDatabaseCount('webhook_inbox_receipts', 0);
    }

    private function postResendWebhook(
        array $event,
        string $eventId,
        ?int $timestamp = null,
        ?string $signature = null,
    ) {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature ??= $this->signature($eventId, $timestamp, $body);

        return $this->call(
            method: 'POST',
            uri: route('webhooks.email', ['provider' => 'resend']),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_SVIX_ID' => $eventId,
                'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
                'HTTP_SVIX_SIGNATURE' => $signature,
            ],
            content: $body,
        );
    }

    private function signature(string $eventId, int $timestamp, string $body): string
    {
        $payload = $eventId.'.'.$timestamp.'.'.$body;

        return 'v1,'.base64_encode(hash_hmac('sha256', $payload, 'test-secret', true));
    }

    private function event(string $type, string $email = 'person@example.com'): array
    {
        return [
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'data' => [
                'email_id' => 'email_123',
                'to' => [$email],
                'subject' => 'Test email',
            ],
        ];
    }
}
