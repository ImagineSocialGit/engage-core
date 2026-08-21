<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\Sms\HandleInboundSmsWebhookAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookPayload;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundSmsReOptInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'client.key' => 'test-client',
            'messaging.sms.inbound.stop_keywords' => ['stop'],
            'messaging.sms.inbound.start_keywords' => ['start', 'unstop'],
            'messaging.sms.inbound.help_keywords' => ['help'],
            'messaging.sms.inbound.start_response' =>
                'You are subscribed to SMS messages again. Reply STOP to opt out.',
            'messaging.sms.inbound.start_no_prior_consent_response' =>
                'We could not restore an earlier SMS subscription.',
        ]);
    }

    public function test_start_restores_only_historical_sms_consent_whose_latest_revocation_was_stop(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'source' => 'test_seed',
            'consented_at' => now()->subDays(2),
        ]);

        app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'source' => 'test_seed',
            'consented_at' => now()->subDays(2),
        ]);

        app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'reason' => ConsentRevocation::REASON_STOP,
            'source' => 'test_stop',
            'revoked_at' => now()->subDay(),
        ]);

        app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'reason' => ConsentRevocation::REASON_MANUAL_REQUEST,
            'source' => 'test_manual',
            'revoked_at' => now()->subDay(),
        ]);

        $response = app(HandleInboundSmsWebhookAction::class)->handle(
            $this->payload(
                eventId: 'evt_start_1',
                messageId: 'msg_start_1',
                body: 'START',
            ),
        );

        $this->assertSame(
            'You are subscribed to SMS messages again. Reply STOP to opt out.',
            $response,
        );

        $inbound = InboundMessage::query()->sole();

        $this->assertSame(InboundMessage::CLASSIFICATION_CONSENT_GRANT, $inbound->classification);
        $this->assertNotNull($inbound->processed_at);
        $this->assertNull($inbound->meta);

        $state = app(MessageConsentStateResolver::class);

        $this->assertTrue($state->isActive(
            contact: $contact,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'campaign',
        ));
        $this->assertFalse($state->isActive(
            contact: $contact,
            channel: 'sms',
            purpose: 'transactional',
            scope: 'webinar',
        ));

        $this->assertSame(
            2,
            MessageConsent::query()
                ->where('contact_id', $contact->getKey())
                ->where('channel', 'sms')
                ->where('purpose', 'marketing')
                ->where('scope', 'campaign')
                ->count(),
        );
        $this->assertSame(
            1,
            MessageConsent::query()
                ->where('contact_id', $contact->getKey())
                ->where('channel', 'sms')
                ->where('purpose', 'transactional')
                ->where('scope', 'webinar')
                ->count(),
        );

        $this->assertSame(
            0,
            AutomationEventOutboxEvent::query()
                ->where('event_key', 'inbound_message.normal_reply')
                ->count(),
        );
    }

    public function test_start_without_prior_sms_consent_does_not_invent_permission(): void
    {
        Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        $response = app(HandleInboundSmsWebhookAction::class)->handle(
            $this->payload(
                eventId: 'evt_start_no_prior',
                messageId: 'msg_start_no_prior',
                body: 'START',
            ),
        );

        $this->assertSame(
            'We could not restore an earlier SMS subscription.',
            $response,
        );
        $this->assertDatabaseCount('message_consents', 0);
        $this->assertSame(
            0,
            AutomationEventOutboxEvent::query()
                ->where('event_key', 'inbound_message.normal_reply')
                ->count(),
        );
    }

    private function payload(
        string $eventId,
        string $messageId,
        string $body,
    ): SmsWebhookPayload {
        return new SmsWebhookPayload(
            provider: 'telnyx',
            eventType: 'message.received',
            isInboundMessage: true,
            providerEventId: $eventId,
            providerMessageId: $messageId,
            providerContextId: null,
            from: '+15551234567',
            to: '+15550001111',
            body: $body,
            receivedAt: now(),
            source: 'telnyx_inbound_sms',
            ipAddress: '127.0.0.1',
            userAgent: 'test-webhook-client',
            raw: [
                'large_provider_payload' => str_repeat('x', 2048),
            ],
        );
    }
}