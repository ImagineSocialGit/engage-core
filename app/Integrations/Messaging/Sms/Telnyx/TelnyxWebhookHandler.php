<?php

namespace App\Integrations\Messaging\Sms\Telnyx;

use App\Contracts\Messaging\SmsWebhookHandler;
use App\Services\Messaging\SmsWebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class TelnyxWebhookHandler implements SmsWebhookHandler
{
    public function provider(): string
    {
        return 'telnyx';
    }

    public function isValid(Request $request): bool
    {
        $signature = $request->header('Telnyx-Signature-Ed25519');
        $timestamp = $request->header('Telnyx-Timestamp');
        $publicKey = config('services.telnyx.webhook_public_key');

        if (
            ! is_string($signature) || trim($signature) === '' ||
            ! is_string($timestamp) || trim($timestamp) === '' ||
            ! is_string($publicKey) || trim($publicKey) === ''
        ) {
            return false;
        }

        if (! extension_loaded('sodium')) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            sodium_hex2bin($signature),
            $timestamp.'|'.$request->getContent(),
            sodium_hex2bin($publicKey),
        );
    }

    public function payloadFrom(Request $request): SmsWebhookPayload
    {
        $data = $request->input('data.payload', []);

        return SmsWebhookPayload::fromRequest(
            provider: $this->provider(),
            request: $request,
            providerMessageId: $this->stringOrNull(data_get($data, 'id')),
            from: $this->stringOrNull(data_get($data, 'from.phone_number')),
            to: $this->stringOrNull(data_get($data, 'to.0.phone_number')),
            body: $this->stringOrNull(data_get($data, 'text')),
            receivedAt: $this->carbonOrNull(data_get($data, 'received_at')),
        );
    }

    public function response(?string $message = null): Response
    {
        return response()->noContent();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? $value
            : null;
    }

    private function carbonOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}