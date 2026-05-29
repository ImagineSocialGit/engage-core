<?php

namespace App\Services\Messaging;

use Illuminate\Http\Request;

class TwilioWebhookVerifier
{
    public function isValid(Request $request): bool
    {
        $signature = $request->header('X-Twilio-Signature');

        if (! is_string($signature) || trim($signature) === '') {
            return false;
        }

        $authToken = config('services.twilio.token');

        if (! is_string($authToken) || trim($authToken) === '') {
            return false;
        }

        return hash_equals(
            $signature,
            $this->signatureFor(
                url: $request->fullUrl(),
                parameters: $request->post(),
                authToken: $authToken,
            ),
        );
    }

    private function signatureFor(string $url, array $parameters, string $authToken): string
    {
        ksort($parameters);

        $payload = $url;

        foreach ($parameters as $key => $value) {
            $payload .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $payload, $authToken, true));
    }
}