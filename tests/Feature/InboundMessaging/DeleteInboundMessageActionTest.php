<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\Inbox\DeleteInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteInboundMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_inbound_message_removes_normalized_record_but_preserves_webhook_and_compliance_evidence(): void
    {
        config()->set('client.key', 'test-client');

        $contact = Contact::factory()->create();

        $receipt = app(WebhookInbox::class)->process(
            provider: 'telnyx',
            payload: [
                'data' => [
                    'id' => 'evt-delete-inbound',
                ],
            ],
            processor: fn (): array => [
                'inbound_message_id' => 999,
            ],
            providerEventId: 'evt-delete-inbound',
            eventType: 'message.received',
        );

        $message = InboundMessage::query()->create([
            'webhook_inbox_receipt_id' => $receipt->getKey(),
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'related_contact_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'evt-delete-inbound',
            'provider_event_key' => hash(
                'sha256',
                'test-client|telnyx|event|evt-delete-inbound',
            ),
            'provider_message_id' => 'msg-delete-inbound',
            'provider_message_key' => hash(
                'sha256',
                'test-client|telnyx|message|msg-delete-inbound',
            ),
            'from_type' => 'phone',
            'from_value' => '+16155550123',
            'to_type' => 'phone',
            'to_value' => '+16155550999',
            'body' => 'STOP',
            'classification' => InboundMessage::CLASSIFICATION_CONSENT_REVOCATION,
            'purpose' => 'marketing',
            'received_at' => now()->subMinute(),
            'processed_at' => now(),
            'inbox_status' => InboundMessage::INBOX_STATUS_DONE,
            'completed_at' => now(),
        ]);

        $revocation = ConsentRevocation::query()->create([
            'contact_id' => $contact->getKey(),
            'message_consent_id' => null,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'channel_purpose',
            'reason' => ConsentRevocation::REASON_STOP,
            'revoked_at' => $message->received_at,
            'source' => 'telnyx_inbound_sms',
            'meta' => [
                'reason_context' => 'inbound_stop_keyword',
                'inbound_message_id' => $message->getKey(),
            ],
        ]);

        app(DeleteInboundMessageAction::class)->handle($message);

        $this->assertDatabaseMissing('inbound_messages', [
            'id' => $message->getKey(),
        ]);
        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'id' => $receipt->getKey(),
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('consent_revocations', [
            'id' => $revocation->getKey(),
            'contact_id' => $contact->getKey(),
            'reason' => ConsentRevocation::REASON_STOP,
        ]);
    }
}