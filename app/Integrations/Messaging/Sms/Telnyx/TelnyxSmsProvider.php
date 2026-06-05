<?php

namespace App\Integrations\Messaging\Sms\Telnyx;

use App\Contracts\Messaging\SmsProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelnyxSmsProvider implements SmsProvider
{
    public function provider(): string
    {
        return 'telnyx';
    }

    public function send(string $to, string $message, array $meta = []): void
    {
        $apiKey = config('services.telnyx.api_key');
        $from = config('sms.providers.telnyx.from');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('Telnyx API key is not configured.');
        }

        if (! is_string($from) || trim($from) === '') {
            throw new RuntimeException('Telnyx from number is not configured.');
        }


        $payload = [
            'from' => $from,
            'to' => $to,
            'text' => $message,
            'use_profile_webhooks' => true,
            'client_state' => filled($meta)
                ? base64_encode(json_encode($meta, JSON_THROW_ON_ERROR))
                : null,
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post(
                'https://api.telnyx.com/v2/messages',
                array_filter($payload, fn ($value) => $value !== null),
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Telnyx SMS send failed from ['.$from.'] to ['.$to.']: '.$response->body(),
            );
        }
    }
}