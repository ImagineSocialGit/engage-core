<?php

namespace Tests\Feature\Webhooks;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\ConsentRevocation;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwilioSmsWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_signature_is_rejected(): void
    {
        config(['services.twilio.token' => 'test-token']);

        $this
            ->withHeader('X-Twilio-Signature', 'invalid-signature')
            ->post(route('webhooks.twilio.sms'), $this->payload(body: 'STOP'))
            ->assertForbidden();
    }

    public function test_stop_revokes_sms_transactional_and_marketing_consent(): void
    {
        config(['services.twilio.token' => 'test-token']);
        config(['sms.webhooks.twilio.stop_response' => 'You have been opted out of SMS messages. Reply START to resubscribe.']);

        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $payload = $this->payload(body: 'STOP', from: '+15555550123');
        $url = route('webhooks.twilio.sms');

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

    public function test_help_returns_help_twiml_without_revoking_consent(): void
    {
        config(['services.twilio.token' => 'test-token']);
        config(['sms.webhooks.twilio.help_response' => 'Reply STOP to opt out of SMS messages. Message and data rates may apply.']);

        Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $payload = $this->payload(body: 'HELP', from: '+15555550123');
        $url = route('webhooks.twilio.sms');

        $this
            ->withHeader('X-Twilio-Signature', $this->twilioSignature($url, $payload))
            ->post($url, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('<Response><Message>Reply STOP to opt out of SMS messages. Message and data rates may apply.</Message></Response>', false);

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_normal_message_returns_empty_twiml_without_revoking_consent(): void
    {
        config(['services.twilio.token' => 'test-token']);

        Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $payload = $this->payload(body: 'Hello', from: '+15555550123');
        $url = route('webhooks.twilio.sms');

        $this
            ->withHeader('X-Twilio-Signature', $this->twilioSignature($url, $payload))
            ->post($url, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('<Response></Response>', false);

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_stop_from_unmatched_phone_returns_stop_twiml_without_revoking_consent(): void
    {
        config(['services.twilio.token' => 'test-token']);
        config(['sms.webhooks.twilio.stop_response' => 'You have been opted out of SMS messages. Reply START to resubscribe.']);

        $payload = $this->payload(body: 'STOP', from: '+15555550999');
        $url = route('webhooks.twilio.sms');

        $this
            ->withHeader('X-Twilio-Signature', $this->twilioSignature($url, $payload))
            ->post($url, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('<Response><Message>You have been opted out of SMS messages. Reply START to resubscribe.</Message></Response>', false);

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    private function payload(string $body, string $from = '+15555550123'): array
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