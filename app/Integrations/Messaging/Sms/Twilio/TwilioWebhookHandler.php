<?php

namespace App\Integrations\Messaging\Sms\Twilio;

use App\Contracts\Messaging\SmsWebhookHandler;
use App\Services\Messaging\SmsWebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TwilioWebhookHandler implements SmsWebhookHandler
{
    public function provider(): string
    {
        return 'twilio';
    }

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
                url: $this->twilioUrl($request),
                parameters: $request->post(),
                authToken: $authToken,
            ),
        );
    }

    public function payloadFrom(Request $request): SmsWebhookPayload
    {
        return SmsWebhookPayload::fromRequest(
            provider: $this->provider(),
            request: $request,
            providerMessageId: $this->stringOrNull($request->input('MessageSid')),
            from: $this->stringOrNull($request->input('From')),
            to: $this->stringOrNull($request->input('To')),
            body: $this->stringOrNull($request->input('Body')),
        );
    }

    public function response(?string $message = null): Response
    {
        $body = $message
            ? '<Response><Message>'.e($message).'</Message></Response>'
            : '<Response></Response>';

        return response($body, 200)
            ->header('Content-Type', 'text/xml');
    }

    private function twilioUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost().$request->getRequestUri();
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

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? $value
            : null;
    }
}