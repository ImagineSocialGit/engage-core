<?php

namespace Tests\Feature\Webhooks;

use App\Integrations\Messaging\Sms\Telnyx\TelnyxWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TelnyxWebhookTimestampValidationTest extends TestCase
{
    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('The sodium extension is required.');
        }

        Carbon::setTestNow('2026-07-19 03:00:00 UTC');

        $keyPair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);

        config()->set(
            'services.telnyx.webhook_public_key',
            base64_encode(sodium_crypto_sign_publickey($keyPair)),
        );
        config()->set('services.telnyx.max_timestamp_drift_seconds', 300);
    }

    public function test_current_cryptographically_valid_request_is_accepted(): void
    {
        $request = $this->signedRequest(Carbon::now()->getTimestamp());

        $this->assertTrue((new TelnyxWebhookHandler())->isValid($request));
    }

    public function test_stale_and_excessively_future_valid_signatures_are_rejected(): void
    {
        $handler = new TelnyxWebhookHandler();

        $this->assertFalse($handler->isValid(
            $this->signedRequest(Carbon::now()->subSeconds(301)->getTimestamp()),
        ));
        $this->assertFalse($handler->isValid(
            $this->signedRequest(Carbon::now()->addSeconds(301)->getTimestamp()),
        ));
    }

    private function signedRequest(int $timestamp): Request
    {
        $body = json_encode([
            'data' => [
                'id' => 'evt_timestamp_test',
                'event_type' => 'message.received',
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) $timestamp;
        $signature = sodium_crypto_sign_detached(
            $timestamp.'|'.$body,
            $this->secretKey,
        );
        $request = Request::create(
            uri: '/webhooks/sms/telnyx',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $request->headers->set('Telnyx-Timestamp', $timestamp);
        $request->headers->set(
            'Telnyx-Signature-Ed25519',
            base64_encode($signature),
        );

        return $request;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}