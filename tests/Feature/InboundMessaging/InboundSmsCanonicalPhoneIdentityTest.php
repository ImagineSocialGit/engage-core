<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\Sms\HandleInboundSmsWebhookAction;
use App\Modules\InboundMessaging\Actions\Sms\Inbound\RevokeSmsConsentFromInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Sms\InboundSmsSenderResolver;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookPayload;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundSmsCanonicalPhoneIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const MARKETING_PROFILE_ID = 'canonical-phone-marketing-profile';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('messaging.inbound.handlers.sms', [
            InboundMessage::CLASSIFICATION_CONSENT_REVOCATION => [
                RevokeSmsConsentFromInboundMessageAction::class,
            ],
            InboundMessage::CLASSIFICATION_CONSENT_GRANT => [],
            InboundMessage::CLASSIFICATION_HELP => [],
            InboundMessage::CLASSIFICATION_NORMAL_REPLY => [],
        ]);
        config()->set('messaging.sms.inbound', [
            'stop_keywords' => ['stop'],
            'start_keywords' => ['start'],
            'help_keywords' => ['help'],
            'stop_response' => 'SMS opt-out confirmed.',
        ]);
        config()->set('sms.providers.telnyx.profile_ids', [
            'marketing' => self::MARKETING_PROFILE_ID,
        ]);
    }

    public function test_sender_resolution_matches_legacy_contact_phone_to_e164_inbound_number(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '6155551212',
        ]);

        $resolved = app(InboundSmsSenderResolver::class)->resolve(
            '+1 (615) 555-1212',
        );

        $this->assertSame($contact->getKey(), $resolved?->getKey());
    }

    public function test_sender_resolution_refuses_ambiguous_canonical_phone_identity(): void
    {
        Contact::factory()->create([
            'phone' => '6155551212',
        ]);
        Contact::factory()->create([
            'phone' => '+1 (615) 555-1212',
        ]);

        $this->assertNull(
            app(InboundSmsSenderResolver::class)->resolve('+16155551212'),
        );
    }

    public function test_normal_reply_links_legacy_contact_and_correlates_legacy_delivery_destination(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '6155551212',
        ]);

        $scheduled = ScheduledMessage::factory()
            ->forContact($contact)
            ->sms()
            ->sent()
            ->create([
                'purpose' => 'marketing',
                'scope' => 'mortgage_homebuyer_nurture',
                'send_at' => now()->subHour(),
            ]);

        $scheduled->latestDeliveryAttempt()->update([
            'destination' => '6155551212',
        ]);

        app(HandleInboundSmsWebhookAction::class)->handle(
            $this->payload(
                body: 'Please call me.',
                eventId: 'evt_canonical_reply',
                messageId: 'msg_canonical_reply',
            ),
        );

        $inbound = InboundMessage::query()
            ->where('provider_event_id', 'evt_canonical_reply')
            ->firstOrFail();

        $this->assertSame($contact->getMorphClass(), $inbound->sender_type);
        $this->assertSame($contact->getKey(), $inbound->sender_id);
        $this->assertSame('+16155551212', $inbound->from_value);
        $this->assertSame(
            $scheduled->getKey(),
            $inbound->correlated_scheduled_message_id,
        );
        $this->assertSame('marketing', $inbound->purpose?->value);
        $this->assertSame('mortgage_homebuyer_nurture', $inbound->scope);
        $this->assertSame('heuristic', $inbound->reply_correlation_method);
    }

    public function test_stop_from_legacy_contact_phone_revokes_sms_consent_for_resolved_contact(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '6155551212',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subDay(),
            'source' => 'test',
        ]);

        app(HandleInboundSmsWebhookAction::class)->handle(
            $this->payload(
                body: 'STOP',
                eventId: 'evt_canonical_stop',
                messageId: 'msg_canonical_stop',
            ),
        );

        $inbound = InboundMessage::query()
            ->where('provider_event_id', 'evt_canonical_stop')
            ->firstOrFail();

        $this->assertSame($contact->getMorphClass(), $inbound->sender_type);
        $this->assertSame($contact->getKey(), $inbound->sender_id);
        $this->assertNotNull($inbound->processed_at);

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'channel_purpose',
            'reason' => ConsentRevocation::REASON_STOP,
            'source' => 'telnyx_inbound_sms',
        ]);
    }

    private function payload(
        string $body,
        string $eventId,
        string $messageId,
    ): SmsWebhookPayload {
        return new SmsWebhookPayload(
            provider: 'telnyx',
            eventType: 'message.received',
            isInboundMessage: true,
            providerEventId: $eventId,
            providerMessageId: $messageId,
            providerContextId: self::MARKETING_PROFILE_ID,
            from: '+1 (615) 555-1212',
            to: '+1 (615) 555-9999',
            body: $body,
            receivedAt: now(),
            source: 'telnyx_inbound_sms',
            ipAddress: null,
            userAgent: null,
            raw: [],
        );
    }
}