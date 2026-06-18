<?php

namespace Tests\Feature\Webhooks;

use App\Actions\Messaging\Sms\Inbound\RespondToSmsHelpInboundMessageAction;
use App\Actions\Messaging\Sms\Inbound\RevokeSmsConsentFromInboundMessageAction;
use App\Contracts\Messaging\Sms\SmsWebhookHandler;
use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Models\InboundMessage;
use App\Models\MessageConsent;
use App\Services\Messaging\Sms\SmsWebhookHandlerResolver;
use App\Services\Messaging\Sms\SmsWebhookPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TelnyxInboundSmsWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const MARKETING_PROFILE_ID = 'telnyx-marketing-profile';
    private const TRANSACTIONAL_PROFILE_ID = 'telnyx-transactional-profile';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');

        config()->set('messaging.inbound.handlers.sms', [
            InboundMessage::CLASSIFICATION_CONSENT_REVOCATION => [
                RevokeSmsConsentFromInboundMessageAction::class,
            ],
            InboundMessage::CLASSIFICATION_HELP => [
                RespondToSmsHelpInboundMessageAction::class,
            ],
            InboundMessage::CLASSIFICATION_NORMAL_REPLY => [],
        ]);

        config()->set('messaging.sms.inbound', [
            'stop_keywords' => [
                'stop',
                'stopall',
                'unsubscribe',
                'cancel',
                'end',
                'quit',
            ],
            'help_keywords' => [
                'help',
                'info',
            ],
            'stop_response' => 'You have been opted out of SMS messages. Reply START to resubscribe.',
            'help_response' => 'Reply STOP to opt out of SMS messages. Message and data rates may apply.',
        ]);

        config()->set('sms.providers.telnyx.profile_ids', [
            'marketing' => self::MARKETING_PROFILE_ID,
            'transactional' => self::TRANSACTIONAL_PROFILE_ID,
        ]);

        $this->app->singleton(SmsWebhookHandlerResolver::class, function () {
            return new SmsWebhookHandlerResolver([
                'telnyx' => new FakeSmsWebhookHandler(),
            ]);
        });
    }

    public function test_telnyx_non_inbound_event_is_ignored(): void
    {
        $this->postTelnyxWebhook([
            'event_type' => 'message.sent',
            'is_inbound' => false,
            'body' => 'STOP',
        ])->assertOk();

        $this->assertDatabaseCount('inbound_messages', 0);
        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_telnyx_inbound_help_stores_inbound_message_and_returns_help_response(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        $response = $this->postTelnyxWebhook([
            'provider_context_id' => self::TRANSACTIONAL_PROFILE_ID,
            'from' => '+1 (555) 123-4567',
            'to' => '+1 (555) 000-1111',
            'body' => 'HELP',
        ]);

        $response
            ->assertOk()
            ->assertSee('Reply STOP to opt out of SMS messages.');

        $this->assertDatabaseHas('inbound_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_context_id' => self::TRANSACTIONAL_PROFILE_ID,
            'from_type' => 'phone',
            'from_value' => '+15551234567',
            'to_type' => 'phone',
            'to_value' => '+15550001111',
            'body' => 'HELP',
            'classification' => InboundMessage::CLASSIFICATION_HELP,
            'purpose' => 'transactional',
        ]);

        $this->assertNotNull(InboundMessage::query()->first()?->processed_at);
        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_telnyx_inbound_normal_reply_stores_inbound_message_only(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        $this->postTelnyxWebhook([
            'provider_context_id' => self::MARKETING_PROFILE_ID,
            'from' => '+15551234567',
            'to' => '+15550001111',
            'body' => 'I am interested',
        ])->assertOk();

        $this->assertDatabaseHas('inbound_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_context_id' => self::MARKETING_PROFILE_ID,
            'from_type' => 'phone',
            'from_value' => '+15551234567',
            'to_type' => 'phone',
            'to_value' => '+15550001111',
            'body' => 'I am interested',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'processed_at' => null,
        ]);

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_telnyx_stop_from_marketing_profile_revokes_marketing_sms_only(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        $this->grantSmsConsent($contact, 'marketing', 'webinar');
        $this->grantSmsConsent($contact, 'transactional', 'webinar');

        $this->postTelnyxWebhook([
            'provider_context_id' => self::MARKETING_PROFILE_ID,
            'from' => '+15551234567',
            'body' => 'STOP',
        ])->assertOk();

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'reason' => ConsentRevocation::REASON_STOP,
            'source' => 'telnyx_inbound_sms',
        ]);

        $this->assertDatabaseMissing('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
        ]);

        $this->assertDatabaseCount('consent_revocations', 1);
    }

    public function test_telnyx_stop_from_transactional_profile_revokes_transactional_sms_only(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        $this->grantSmsConsent($contact, 'marketing', 'webinar');
        $this->grantSmsConsent($contact, 'transactional', 'webinar');

        $this->postTelnyxWebhook([
            'provider_context_id' => self::TRANSACTIONAL_PROFILE_ID,
            'from' => '+15551234567',
            'body' => 'STOP',
        ])->assertOk();

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'reason' => ConsentRevocation::REASON_STOP,
            'source' => 'telnyx_inbound_sms',
        ]);

        $this->assertDatabaseMissing('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
        ]);

        $this->assertDatabaseCount('consent_revocations', 1);
    }

    public function test_telnyx_stop_from_unknown_profile_revokes_all_active_sms_consent(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        $this->grantSmsConsent($contact, 'marketing', 'webinar');
        $this->grantSmsConsent($contact, 'marketing', 'webinar_waitlist');
        $this->grantSmsConsent($contact, 'transactional', 'webinar');

        $this->postTelnyxWebhook([
            'provider_context_id' => 'unknown-profile-id',
            'from' => '+15551234567',
            'body' => 'STOP',
        ])->assertOk();

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'reason' => ConsentRevocation::REASON_STOP,
        ]);

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'reason' => ConsentRevocation::REASON_STOP,
        ]);

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'reason' => ConsentRevocation::REASON_STOP,
        ]);

        $this->assertDatabaseCount('consent_revocations', 3);
    }

    public function test_telnyx_stop_with_no_matching_contact_stores_inbound_message_but_does_not_revoke(): void
    {
        $this->postTelnyxWebhook([
            'provider_context_id' => self::MARKETING_PROFILE_ID,
            'from' => '+15551234567',
            'body' => 'STOP',
        ])->assertOk();

        $this->assertDatabaseHas('inbound_messages', [
            'recipient_type' => null,
            'recipient_id' => null,
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_context_id' => self::MARKETING_PROFILE_ID,
            'from_type' => 'phone',
            'from_value' => '+15551234567',
            'classification' => InboundMessage::CLASSIFICATION_CONSENT_REVOCATION,
            'purpose' => 'marketing',
            'processed_at' => null,
        ]);

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    private function grantSmsConsent(Contact $contact, string $purpose, string $scope): MessageConsent
    {
        return MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => $purpose,
            'scope' => $scope,
            'consented_at' => now()->subDay(),
            'source' => 'test',
        ]);
    }

    private function postTelnyxWebhook(array $payload)
    {
        return $this->post(route('webhooks.sms', ['provider' => 'telnyx']), array_merge([
            'event_type' => 'message.received',
            'is_inbound' => true,
            'provider_event_id' => 'evt_'.str()->random(8),
            'provider_message_id' => 'msg_'.str()->random(8),
            'provider_context_id' => self::TRANSACTIONAL_PROFILE_ID,
            'from' => '+15551234567',
            'to' => '+15550001111',
            'body' => 'Hello',
            'received_at' => now()->toIso8601String(),
        ], $payload));
    }
}

class FakeSmsWebhookHandler implements SmsWebhookHandler
{
    public function provider(): string
    {
        return 'telnyx';
    }

    public function isValid(Request $request): bool
    {
        return true;
    }

    public function payloadFrom(Request $request): SmsWebhookPayload
    {
        return SmsWebhookPayload::fromRequest(
            provider: $this->provider(),
            request: $request,
            eventType: $request->input('event_type'),
            isInboundMessage: (bool) $request->input('is_inbound', true),
            providerEventId: $request->input('provider_event_id'),
            providerMessageId: $request->input('provider_message_id'),
            providerContextId: $request->input('provider_context_id'),
            from: $request->input('from'),
            to: $request->input('to'),
            body: $request->input('body'),
            receivedAt: Carbon::parse($request->input('received_at')),
        );
    }

    public function response(?string $message = null): Response
    {
        return response($message ?? '', 200);
    }
}