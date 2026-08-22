<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Support\EmailReplyAddressGenerator;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResendInboundReplyEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'client.key' => 'test-client',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.resend.key' => 'resend-test-key',
            'services.resend.webhook_secret' => 'test-secret',
            'services.resend.webhook_timestamp_drift_seconds' => 300,
            'messaging.email.inbound_domain' => 'replies.example.test',
            'messaging.reply_profiles.test_profile' => [
                'intents' => [
                    'yes' => [
                        'exact' => ['yes'],
                        'keywords' => ['absolutely'],
                    ],
                ],
            ],
        ]);
    }

    public function test_resend_received_webhook_retrieves_correlates_classifies_and_records_compact_automation_event(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'reply@example.com',
        ]);
        $scheduled = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->sent()
            ->create([
                'purpose' => 'marketing',
                'scope' => 'test_scope',
                'reply_profile_key' => 'test_profile',
            ]);
        $replyTo = app(EmailReplyAddressGenerator::class)
            ->forScheduledMessage($scheduled);

        $this->assertNotNull($replyTo);

        Http::fake([
            'https://api.resend.com/emails/receiving/received_email_1' => Http::response([
                'id' => 'received_email_1',
                'from' => 'Reply Person <reply@example.com>',
                'to' => [$replyTo],
                'subject' => 'Re: Checking in',
                'message_id' => '<received-email-1@example.test>',
                'text' => "YES!\n\nOn Wed, Someone wrote:\n> old content",
                'html' => null,
                'created_at' => now()->toISOString(),
            ]),
        ]);

        $event = [
            'type' => 'email.received',
            'created_at' => now()->toISOString(),
            'data' => [
                'email_id' => 'received_email_1',
                'message_id' => '<received-email-1@example.test>',
                'subject' => 'Re: Checking in',
            ],
        ];
        $eventId = 'evt_received_email_1';
        $timestamp = time();

        $this->postResendWebhook($event, $eventId, $timestamp)
            ->assertNoContent();

        $inbound = InboundMessage::query()->sole();

        $this->assertSame($contact->getKey(), $inbound->sender_id);
        $this->assertSame(InboundMessage::CLASSIFICATION_NORMAL_REPLY, $inbound->classification);
        $this->assertSame($scheduled->getKey(), $inbound->correlated_scheduled_message_id);
        $this->assertSame('Re: Checking in', $inbound->subject);
        $this->assertSame('<received-email-1@example.test>', $inbound->message_id);
        $this->assertSame('exact', $inbound->reply_correlation_method);
        $this->assertSame('yes', $inbound->reply_intent_key);

        $outbox = AutomationEventOutboxEvent::query()
            ->where('event_key', 'inbound_message.normal_reply')
            ->sole();

        $this->assertSame(
            'test_profile',
            data_get($outbox->payload, 'inbound_message.reply_profile_key'),
        );
        $this->assertSame(
            'yes',
            data_get($outbox->payload, 'inbound_message.reply_intent_key'),
        );
        $this->assertSame(
            $scheduled->getKey(),
            data_get($outbox->payload, 'inbound_message.scheduled_message_id'),
        );
        $this->assertNull(data_get($outbox->payload, 'inbound_message.body'));

        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'client_key' => 'test-client',
            'provider' => 'resend',
            'provider_event_id' => $eventId,
            'event_type' => 'email.received',
            'status' => WebhookInboxReceipt::STATUS_COMPLETED,
            'attempts' => 1,
        ]);

        $this->postResendWebhook($event, $eventId, $timestamp)
            ->assertNoContent();

        $this->assertDatabaseCount('inbound_messages', 1);
        $this->assertDatabaseCount('inbound_message_receipts', 1);
        $this->assertDatabaseCount('webhook_inbox_receipts', 1);
        $this->assertDatabaseCount('automation_event_outbox_events', 1);

        Http::assertSentCount(1);
    }

    private function postResendWebhook(
        array $event,
        string $eventId,
        int $timestamp,
    ) {
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->call(
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
        );
    }

    private function signature(
        string $eventId,
        int $timestamp,
        string $body,
    ): string {
        return 'v1,'.base64_encode(hash_hmac(
            'sha256',
            $eventId.'.'.$timestamp.'.'.$body,
            'test-secret',
            true,
        ));
    }
}