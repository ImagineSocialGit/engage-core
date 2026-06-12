<?php

namespace Tests\Feature\Webhooks;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\ConsentRevocation;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_twilio_invalid_signature_is_rejected(): void
    {
        config(['services.twilio.token' => 'test-token']);

        $this
            ->withHeader('X-Twilio-Signature', 'invalid-signature')
            ->post(route('webhooks.sms', ['provider' => 'twilio']), $this->twilioPayload(body: 'STOP'))
            ->assertForbidden();
    }

    public function test_twilio_stop_revokes_sms_transactional_and_marketing_consent(): void
    {
        config(['services.twilio.token' => 'test-token']);

        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $payload = $this->twilioPayload(body: 'STOP', from: '+15555550123');
        $url = route('webhooks.sms', ['provider' => 'twilio']);

        $this
            ->withHeader('X-Twilio-Signature', $this->twilioSignature($url, $payload))
            ->post($url, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('<Response><Message>You have been opted out of SMS messages. Reply START to resubscribe.</Message></Response>', false);

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Sms->value,
            'purpose' => MessagePurpose::Transactional->value,
            'reason' => ConsentRevocation::REASON_STOP,
            'source' => 'twilio_inbound_sms',
        ]);

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Sms->value,
            'purpose' => MessagePurpose::Marketing->value,
            'reason' => ConsentRevocation::REASON_STOP,
            'source' => 'twilio_inbound_sms',
        ]);

        $this->assertDatabaseCount('consent_revocations', 2);
    }

    public function test_twilio_help_returns_help_twiml_without_revoking_consent(): void
    {
        config(['services.twilio.token' => 'test-token']);

        Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $payload = $this->twilioPayload(body: 'HELP', from: '+15555550123');
        $url = route('webhooks.sms', ['provider' => 'twilio']);

        $this
            ->withHeader('X-Twilio-Signature', $this->twilioSignature($url, $payload))
            ->post($url, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('<Response><Message>Reply STOP to opt out of SMS messages. Message and data rates may apply.</Message></Response>', false);

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_twilio_normal_message_returns_empty_twiml_without_revoking_consent(): void
    {
        config(['services.twilio.token' => 'test-token']);

        Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $payload = $this->twilioPayload(body: 'Hello', from: '+15555550123');
        $url = route('webhooks.sms', ['provider' => 'twilio']);

        $this
            ->withHeader('X-Twilio-Signature', $this->twilioSignature($url, $payload))
            ->post($url, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('<Response></Response>', false);

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_twilio_stop_from_unmatched_phone_returns_stop_twiml_without_revoking_consent(): void
    {
        config(['services.twilio.token' => 'test-token']);

        $payload = $this->twilioPayload(body: 'STOP', from: '+15555550999');
        $url = route('webhooks.sms', ['provider' => 'twilio']);

        $this
            ->withHeader('X-Twilio-Signature', $this->twilioSignature($url, $payload))
            ->post($url, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('<Response><Message>You have been opted out of SMS messages. Reply START to resubscribe.</Message></Response>', false);

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_telnyx_invalid_signature_is_rejected(): void
    {
        config(['services.telnyx.webhook_public_key' => str_repeat('a', 64)]);

        $this
            ->withHeaders([
                'Telnyx-Signature-Ed25519' => str_repeat('b', 128),
                'Telnyx-Timestamp' => (string) now()->timestamp,
            ])
            ->postJson(route('webhooks.sms', ['provider' => 'telnyx']), $this->telnyxPayload(body: 'STOP'))
            ->assertForbidden();
    }

    private function twilioPayload(string $body, string $from = '+15555550123'): array
    {
        return [
            'MessageSid' => 'SMXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
            'SmsSid' => 'SMXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
            'SmsMessageSid' => 'SMXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
            'AccountSid' => 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
            'MessagingServiceSid' => 'MGXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
            'From' => $from,
            'To' => '+15555550000',
            'Body' => $body,
            'NumMedia' => '0',
        ];
    }

    private function telnyxPayload(string $body, string $from = '+15555550123'): array
    {
        return [
            'data' => [
                'event_type' => 'message.received',
                'id' => 'event-id',
                'payload' => [
                    'id' => 'message-id',
                    'from' => [
                        'phone_number' => $from,
                    ],
                    'to' => [
                        [
                            'phone_number' => '+15555550000',
                        ],
                    ],
                    'text' => $body,
                    'received_at' => now()->toIso8601String(),
                ],
            ],
        ];
    }

    private function twilioSignature(string $url, array $parameters): string
    {
        ksort($parameters);

        $payload = $url;

        foreach ($parameters as $key => $value) {
            $payload .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $payload, 'test-token', true));
    }
}