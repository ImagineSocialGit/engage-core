<?php

namespace Tests\Feature\Messaging;

use App\Integrations\Messaging\Email\Resend\ResendMessageEventWebhookHandler;
use App\Integrations\Messaging\Email\Resend\ResendWebhookVerifier;
use App\Integrations\Messaging\Sms\Telnyx\TelnyxMessageEventWebhookHandler;
use App\Integrations\Messaging\Sms\Telnyx\TelnyxSmsProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MessageEventWebhookOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_event_and_inbound_routes_have_separate_module_ownership(): void
    {
        $emailEvents = Route::getRoutes()->getByName('webhooks.message-events.email');
        $smsEvents = Route::getRoutes()->getByName('webhooks.message-events.sms');
        $inboundEmail = Route::getRoutes()->getByName('webhooks.inbound.email');
        $inboundSms = Route::getRoutes()->getByName('webhooks.inbound.sms');

        $this->assertNotNull($emailEvents);
        $this->assertNotNull($smsEvents);
        $this->assertNotNull($inboundEmail);
        $this->assertNotNull($inboundSms);

        $this->assertSame('message-events/email/{provider}', $emailEvents->uri());
        $this->assertSame('message-events/sms/{provider}', $smsEvents->uri());
        $this->assertSame('inbound/email/{provider}', $inboundEmail->uri());
        $this->assertSame('inbound/sms/{provider}', $inboundSms->uri());

        $this->assertContains('module:messaging', $emailEvents->gatherMiddleware());
        $this->assertContains('module:messaging', $smsEvents->gatherMiddleware());
        $this->assertContains('module:inbound_messaging', $inboundEmail->gatherMiddleware());
        $this->assertContains('module:inbound_messaging', $inboundSms->gatherMiddleware());
    }

    public function test_legacy_inbound_webhook_aliases_remain_registered_for_cutover(): void
    {
        $legacyEmail = Route::getRoutes()->getByName('webhooks.email');
        $legacySms = Route::getRoutes()->getByName('webhooks.sms');

        $this->assertNotNull($legacyEmail);
        $this->assertNotNull($legacySms);
        $this->assertSame('email/{provider}', $legacyEmail->uri());
        $this->assertSame('sms/{provider}', $legacySms->uri());
        $this->assertContains('module:inbound_messaging', $legacyEmail->gatherMiddleware());
        $this->assertContains('module:inbound_messaging', $legacySms->gatherMiddleware());
    }

    public function test_resend_verifier_accepts_multiple_active_webhook_secrets(): void
    {
        config()->set(
            'services.resend.webhook_secret',
            'first-webhook-secret, second-webhook-secret',
        );
        config()->set('services.resend.webhook_timestamp_drift_seconds', 300);

        Carbon::setTestNow('2026-09-01 20:00:00 UTC');

        $payload = json_encode([
            'type' => 'email.delivered',
            'data' => ['email_id' => 'email_123'],
        ], JSON_THROW_ON_ERROR);
        $eventId = 'evt_secret_rotation';
        $timestamp = (string) Carbon::now()->getTimestamp();
        $signature = base64_encode(hash_hmac(
            'sha256',
            $eventId.'.'.$timestamp.'.'.$payload,
            'second-webhook-secret',
            true,
        ));

        $this->assertTrue(app(ResendWebhookVerifier::class)->isValid(
            payload: $payload,
            headers: [
                'svix-id' => $eventId,
                'svix-timestamp' => $timestamp,
                'svix-signature' => $signature,
            ],
        ));
    }

    public function test_resend_message_event_handler_suppresses_bounced_destination(): void
    {
        config()->set('client.key', 'webhook-test');
        config()->set('services.resend.webhook_secret', 'resend-webhook-secret');
        config()->set('services.resend.webhook_timestamp_drift_seconds', 300);

        Carbon::setTestNow('2026-09-01 20:00:00 UTC');

        $request = $this->signedResendRequest([
            'type' => 'email.bounced',
            'data' => [
                'email_id' => 'email_bounce_1',
                'to' => ['Person@Example.com'],
            ],
        ], 'evt_bounce_message_events');

        $response = app(ResendMessageEventWebhookHandler::class)->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertDatabaseHas('message_suppressions', [
            'channel' => 'email',
            'destination' => 'person@example.com',
            'reason' => MessageSuppression::REASON_BOUNCE,
            'provider' => MessageSuppression::PROVIDER_RESEND,
            'source_event_id' => 'evt_bounce_message_events',
            'released_at' => null,
        ]);
        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'provider' => MessageSuppression::PROVIDER_RESEND,
            'provider_event_id' => 'evt_bounce_message_events',
            'event_type' => 'email.bounced',
            'status' => 'completed',
        ]);
    }

    public function test_resend_contact_updated_unsubscribe_revokes_marketing_email_permission(): void
    {
        config()->set('client.key', 'webhook-test');
        config()->set('services.resend.webhook_secret', 'resend-webhook-secret');

        Carbon::setTestNow('2026-09-01 20:00:00 UTC');

        $contact = Contact::factory()->create([
            'email' => 'unsubscribe@example.com',
        ]);
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'consented_at' => now()->subDay(),
            'source' => 'test',
        ]);

        $request = $this->signedResendRequest([
            'type' => 'contact.updated',
            'data' => [
                'id' => 'contact_123',
                'email' => 'unsubscribe@example.com',
                'unsubscribed' => true,
            ],
        ], 'evt_contact_unsubscribed');

        $response = app(ResendMessageEventWebhookHandler::class)->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'channel_purpose',
            'reason' => ConsentRevocation::REASON_PROVIDER_UNSUBSCRIBE,
            'source' => 'resend_webhook',
        ]);
    }

    public function test_resend_message_event_handler_rejects_inbound_email_event(): void
    {
        config()->set('client.key', 'webhook-test');
        config()->set('services.resend.webhook_secret', 'resend-webhook-secret');

        Carbon::setTestNow('2026-09-01 20:00:00 UTC');

        $request = $this->signedResendRequest([
            'type' => 'email.received',
            'data' => ['email_id' => 'received_1'],
        ], 'evt_wrong_resend_endpoint');

        $response = app(ResendMessageEventWebhookHandler::class)->handle($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseCount('webhook_inbox_receipts', 0);
    }

    public function test_telnyx_outbound_send_uses_message_event_callback_instead_of_profile_webhooks(): void
    {
        config()->set('services.telnyx.api_key', 'test-api-key');
        config()->set('sms.providers.telnyx.from.transactional', '+15550001111');

        Http::fake([
            'https://api.telnyx.com/v2/messages' => Http::response([
                'data' => ['id' => 'message_123'],
            ], 200),
        ]);

        app(TelnyxSmsProvider::class)->send(
            to: '+15550002222',
            message: 'Webhook ownership test',
            meta: ['purpose' => 'transactional'],
        );

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['webhook_url'] ?? null) === route(
                'webhooks.message-events.sms',
                ['provider' => 'telnyx'],
            )
                && ($data['use_profile_webhooks'] ?? null) === false;
        });
    }

    public function test_telnyx_message_event_handler_deduplicates_delivery_callback(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('The sodium extension is required.');
        }

        config()->set('client.key', 'webhook-test');
        config()->set('services.telnyx.max_timestamp_drift_seconds', 300);
        config()->set('sms.providers.telnyx.webhooks.inbound_event_types', [
            'message.received',
        ]);

        Carbon::setTestNow('2026-09-01 20:00:00 UTC');

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        config()->set(
            'services.telnyx.webhook_public_key',
            base64_encode(sodium_crypto_sign_publickey($keyPair)),
        );

        $body = json_encode([
            'data' => [
                'id' => 'evt_telnyx_finalized_1',
                'event_type' => 'message.finalized',
                'payload' => [
                    'id' => 'message_123',
                    'to' => [[
                        'phone_number' => '+15550002222',
                        'status' => 'delivered',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) Carbon::now()->getTimestamp();
        $signature = base64_encode(sodium_crypto_sign_detached(
            $timestamp.'|'.$body,
            $secretKey,
        ));

        $request = Request::create(
            uri: '/message-events/sms/telnyx',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $request->headers->set('Telnyx-Timestamp', $timestamp);
        $request->headers->set('Telnyx-Signature-Ed25519', $signature);

        $handler = app(TelnyxMessageEventWebhookHandler::class);

        $this->assertSame(204, $handler->handle($request)->getStatusCode());
        $this->assertSame(204, $handler->handle($request)->getStatusCode());
        $this->assertDatabaseCount('webhook_inbox_receipts', 1);
        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'provider' => MessageSuppression::PROVIDER_TELNYX,
            'provider_event_id' => 'evt_telnyx_finalized_1',
            'event_type' => 'message.finalized',
            'status' => 'completed',
        ]);
    }

    private function signedResendRequest(array $payload, string $eventId): Request
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) Carbon::now()->getTimestamp();
        $secret = (string) config('services.resend.webhook_secret');
        $signature = base64_encode(hash_hmac(
            'sha256',
            $eventId.'.'.$timestamp.'.'.$body,
            $secret,
            true,
        ));

        $request = Request::create(
            uri: '/message-events/email/resend',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $request->headers->set('svix-id', $eventId);
        $request->headers->set('svix-timestamp', $timestamp);
        $request->headers->set('svix-signature', $signature);

        return $request;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}