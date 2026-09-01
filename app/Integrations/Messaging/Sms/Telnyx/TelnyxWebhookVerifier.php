<?php

namespace App\Integrations\Messaging\Sms\Telnyx;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class TelnyxWebhookVerifier
{
    public function isValid(Request $request): bool
    {
        $signature = $request->header('Telnyx-Signature-Ed25519');
        $timestamp = $request->header('Telnyx-Timestamp');
        $publicKey = config('services.telnyx.webhook_public_key');

        if (
            ! is_string($signature) || trim($signature) === ''
            || ! is_string($timestamp) || ! $this->hasFreshTimestamp($timestamp)
            || ! is_string($publicKey) || trim($publicKey) === ''
        ) {
            return false;
        }

        if (! extension_loaded('sodium')) {
            return false;
        }

        $decodedSignature = base64_decode($signature, true);
        $decodedPublicKey = base64_decode($publicKey, true);

        if ($decodedSignature === false || $decodedPublicKey === false) {
            return false;
        }

        if (strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        if (strlen($decodedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $decodedSignature,
            trim($timestamp).'|'.$request->getContent(),
            $decodedPublicKey,
        );
    }

    private function hasFreshTimestamp(string $timestamp): bool
    {
        $timestamp = trim($timestamp);

        if (! preg_match('/^\d{1,11}$/', $timestamp)) {
            return false;
        }

        $maxDrift = max(1, (int) config(
            'services.telnyx.max_timestamp_drift_seconds',
            300,
        ));

        return abs(Carbon::now()->getTimestamp() - (int) $timestamp) <= $maxDrift;
    }
}