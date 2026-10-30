<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Actions\RecoverIncompleteInboundWebhooksAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class IncompleteInboundWebhookRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'recovery-client');
        config()->set('services.resend.key', 'recovery-test-key');
        config()->set('webhooks.inbox.recovery.enabled', true);
        config()->set('webhooks.inbox.recovery.batch_size', 25);
        config()->set('webhooks.inbox.recovery.minimum_age_seconds', 60);
        config()->set('webhooks.inbox.recovery.retry_delay_seconds', 60);
        config()->set('webhooks.inbox.recovery.max_attempts', 4);
        config()->set('sms.providers.telnyx.webhooks.inbound_event_types', ['message.received']);
        config()->set('messaging.sms.inbound.help_keywords', ['help', 'info']);
        config()->set('messaging.sms.inbound.help_response', 'Reply STOP to opt out.');
    }

    public function test_lost_resend_inbound_work_recovers_once_from_a_stored_verified_receipt(): void
    {
        Contact::factory()->create(['email' => 'sender@example.com']);

        $payload = [
            'type' => 'email.received',
            'data' => ['email_id' => 'recovery-email-1'],
        ];
        $receipt = $this->failedReceipt('resend', 'evt-recovery-email-1', 'email.received', $payload);

        Http::fake([
            'https://api.resend.com/emails/receiving/recovery-email-1' => Http::response([
                'id' => 'recovery-email-1',
                'from' => 'sender@example.com',
                'to' => ['contact@example.test'],
                'text' => 'I have a question.',
                'created_at' => now()->toISOString(),
            ]),
        ]);

        $this->travel(2)->minutes();

        $recovery = app(RecoverIncompleteInboundWebhooksAction::class);
        $this->assertSame(1, $recovery->handle(), $this->recoveryDiagnostic($receipt));
        $this->assertSame(0, $recovery->handle());

        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $receipt->fresh()->status);
        $this->assertSame(2, $receipt->fresh()->attempts);
        $this->assertSame(1, InboundMessage::query()->count());
        $message = InboundMessage::query()->sole();
        $this->assertSame($receipt->getKey(), $message->webhook_inbox_receipt_id);
        $this->assertNotNull($message->processed_at);
        $this->assertSame(InboundMessage::INBOX_STATUS_NEW, $message->inbox_status);
        $this->assertSame(1, AutomationEventOutboxEvent::query()
            ->where('event_key', RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY)
            ->count());
        Http::assertSentCount(1);
    }

    public function test_replay_resumes_an_already_recorded_email_without_duplicate_event(): void
    {
        $contact = Contact::factory()->create(['email' => 'returning@example.com']);
        $receipt = $this->failedReceipt('resend', 'evt-existing-message', 'email.received', [
            'type' => 'email.received',
            'data' => ['email_id' => 'existing-email'],
        ]);
        $message = app(RecordInboundMessageAction::class)->handle(
            data: [
                'channel' => 'email',
                'provider' => 'resend',
                'provider_event_id' => 'evt-existing-message',
                'provider_message_id' => 'existing-email',
                'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
                'received_at' => now(),
            ],
            sender: $contact,
        );

        Http::fake([
            'https://api.resend.com/emails/receiving/existing-email' => Http::response([
                'id' => 'existing-email',
                'from' => 'returning@example.com',
                'to' => ['contact@example.test'],
                'text' => 'Already recorded.',
                'created_at' => now()->toISOString(),
            ]),
        ]);

        $this->travel(2)->minutes();
        $this->assertSame(1, app(RecoverIncompleteInboundWebhooksAction::class)->handle(), $this->recoveryDiagnostic($receipt));
        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $receipt->fresh()->status);
        $this->assertSame($message->getKey(), InboundMessage::query()->sole()->getKey());
        $this->assertSame(1, AutomationEventOutboxEvent::query()
            ->where('event_key', RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY)
            ->count());
    }

    public function test_lost_telnyx_inbound_help_recovers_without_new_provider_request(): void
    {
        $payload = [
            'data' => [
                'id' => 'evt-recovery-help',
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg-recovery-help',
                    'from' => ['phone_number' => '+15551234567'],
                    'to' => [['phone_number' => '+15550001111']],
                    'text' => 'HELP',
                    'received_at' => now()->toISOString(),
                ],
            ],
        ];
        $receipt = $this->failedReceipt('telnyx', 'evt-recovery-help', 'message.received', $payload);

        $this->travel(2)->minutes();

        $recovery = app(RecoverIncompleteInboundWebhooksAction::class);
        $this->assertSame(1, $recovery->handle(), $this->recoveryDiagnostic($receipt));
        $this->assertSame(0, $recovery->handle());

        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $receipt->fresh()->status);
        $this->assertSame(2, $receipt->fresh()->attempts);
        $this->assertNotNull(InboundMessage::query()->sole()->processed_at);
        $this->assertSame($receipt->getKey(), InboundMessage::query()->sole()->webhook_inbox_receipt_id);
        $this->assertNotEmpty(data_get($receipt->fresh()->outcome, 'response_message'));
    }

    public function test_recovery_preserves_the_authenticated_payload_fingerprint_and_ingress_mismatch_guard(): void
    {
        Contact::factory()->create(['email' => 'anonymous@example.com']);

        $original = [
            'type' => 'email.received',
            'data' => ['email_id' => 'immutable-original'],
        ];
        $receipt = $this->failedReceipt('resend', 'evt-immutable-original', 'email.received', $original);
        $fingerprint = $receipt->payload_fingerprint;

        Http::fake([
            'https://api.resend.com/emails/receiving/immutable-original' => Http::response([
                'id' => 'immutable-original',
                'from' => 'anonymous@example.com',
                'to' => ['contact@example.test'],
                'text' => 'Retained payload.',
                'created_at' => now()->toISOString(),
            ]),
        ]);

        $this->travel(2)->minutes();
        $this->assertSame(1, app(RecoverIncompleteInboundWebhooksAction::class)->handle(), $this->recoveryDiagnostic($receipt));

        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $receipt->fresh()->status);
        $this->assertSame($fingerprint, $receipt->fresh()->payload_fingerprint);
        $this->assertSame(2, $receipt->fresh()->attempts);

        try {
            app(WebhookInbox::class)->process(
                provider: 'resend',
                providerEventId: 'evt-immutable-original',
                eventType: 'email.received',
                payload: ['type' => 'email.received', 'data' => ['email_id' => 'different-payload']],
                processor: static fn (): array => ['unexpected' => true],
            );
            $this->fail('An existing provider identity must reject a different incoming payload.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('was reused for a different request', $exception->getMessage());
        }

        $this->assertSame(2, $receipt->fresh()->attempts);
        $this->assertDatabaseCount('webhook_inbox_receipts', 1);
    }

    public function test_active_receipt_claim_is_not_stolen_but_expired_claim_can_recover(): void
    {
        $receipt = $this->failedReceipt('telnyx', 'evt-active-claim', 'message.received', [
            'data' => [
                'id' => 'evt-active-claim',
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg-active-claim',
                    'from' => ['phone_number' => '+15551234567'],
                    'to' => [['phone_number' => '+15550001111']],
                    'text' => 'HELP',
                ],
            ],
        ]);
        $receipt->forceFill([
            'status' => WebhookInboxReceipt::STATUS_PROCESSING,
            'claim_token' => 'worker-still-active',
            'claim_expires_at' => now()->addMinutes(20),
        ])->save();

        $this->travel(2)->minutes();
        $this->assertSame(0, app(RecoverIncompleteInboundWebhooksAction::class)->handle());
        $this->assertSame('worker-still-active', $receipt->fresh()->claim_token);

        $this->travel(20)->minutes();
        $this->assertSame(1, app(RecoverIncompleteInboundWebhooksAction::class)->handle(), $this->recoveryDiagnostic($receipt));
        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $receipt->fresh()->status);
        $this->assertSame(2, $receipt->fresh()->attempts);
    }

    public function test_completed_inbound_reply_is_not_replayed_only_because_processed_at_is_null(): void
    {
        $receipt = app(WebhookInbox::class)->process(
            provider: 'resend',
            payload: ['type' => 'email.received', 'data' => ['email_id' => 'already-completed']],
            providerEventId: 'evt-already-completed',
            eventType: 'email.received',
            processor: static fn (): array => ['http_status' => 204],
        );
        $message = app(RecordInboundMessageAction::class)->handle([
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'evt-already-completed',
            'provider_message_id' => 'already-completed',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
        ]);
        $this->assertNull($message->processed_at);

        $this->travel(20)->minutes();
        $this->assertSame(0, app(RecoverIncompleteInboundWebhooksAction::class)->handle());
        $this->assertSame(1, $receipt->fresh()->attempts);
        $this->assertSame(1, InboundMessage::query()->count());
    }

    public function test_other_client_delivery_event_and_exhausted_receipt_are_not_replayed(): void
    {
        $deliveryEvent = $this->failedReceipt('resend', 'evt-delivery', 'email.bounced', [
            'type' => 'email.bounced',
        ]);
        $exhausted = $this->failedReceipt('resend', 'evt-exhausted', 'email.received', [
            'type' => 'email.received',
            'data' => ['email_id' => 'exhausted-email'],
        ]);
        $exhausted->forceFill(['attempts' => 4])->save();
        $otherClient = $this->failedReceipt('resend', 'evt-other-client', 'email.received', [
            'type' => 'email.received',
            'data' => ['email_id' => 'other-client-email'],
        ]);
        $otherClient->forceFill(['client_key' => 'other-client'])->save();

        $this->travel(20)->minutes();
        $this->assertSame(0, app(RecoverIncompleteInboundWebhooksAction::class)->handle());
        $this->assertSame(1, $deliveryEvent->fresh()->attempts);
        $this->assertSame(4, $exhausted->fresh()->attempts);
        $this->assertSame(1, $otherClient->fresh()->attempts);
        $this->assertSame(0, InboundMessage::query()->count());
    }

    /**
     * This temporary diagnostic preserves the exact passing/failing assertion
     * while surfacing whether the scan skipped a receipt or replay failed.
     */
    private function recoveryDiagnostic(WebhookInboxReceipt $receipt): string
    {
        $fresh = $receipt->fresh() ?? $receipt;

        return json_encode([
            'now' => now()->toDateTimeString(),
            'configured_client_key' => config('client.key'),
            'configured_recovery' => config('webhooks.inbox.recovery'),
            'configured_telnyx_inbound_event_types' => config('sms.providers.telnyx.webhooks.inbound_event_types'),
            'receipt' => [
                'id' => $fresh->getKey(),
                'client_key' => $fresh->client_key,
                'provider' => $fresh->provider,
                'provider_event_id' => $fresh->provider_event_id,
                'event_type' => $fresh->event_type,
                'status' => $fresh->status,
                'attempts' => $fresh->attempts,
                'created_at' => $fresh->created_at?->toDateTimeString(),
                'last_attempted_at' => $fresh->last_attempted_at?->toDateTimeString(),
                'claim_expires_at' => $fresh->claim_expires_at?->toDateTimeString(),
                'last_error' => $fresh->last_error,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function failedReceipt(
        string $provider,
        string $eventId,
        string $eventType,
        array $payload,
    ): WebhookInboxReceipt {
        try {
            app(WebhookInbox::class)->process(
                provider: $provider,
                payload: $payload,
                providerEventId: $eventId,
                eventType: $eventType,
                processor: static function (): never {
                    throw new RuntimeException('Simulated server interruption after receipt persistence.');
                },
            );
            $this->fail('Simulated interruption should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated server interruption after receipt persistence.',
                $exception->getMessage(),
            );
        }

        $receipt = WebhookInboxReceipt::query()->where('provider_event_id', $eventId)->sole();
        $this->assertSame(WebhookInboxReceipt::STATUS_RETRYABLE_FAILED, $receipt->status);

        return $receipt;
    }
}