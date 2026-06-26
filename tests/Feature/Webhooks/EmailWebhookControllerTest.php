<?php

namespace Tests\Feature\Webhooks;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EmailWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
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
    }

    public function test_valid_bounce_event_creates_email_suppression(): void
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
    }

    public function test_replayed_event_is_rejected(): void
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
            ->assertForbidden();

        $this->assertDatabaseCount('message_suppressions', 1);
    }

    public function test_malformed_json_is_rejected_after_valid_signature(): void
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