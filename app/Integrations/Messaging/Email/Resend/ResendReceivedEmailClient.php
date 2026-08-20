<?php

namespace App\Integrations\Messaging\Email\Resend;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ResendReceivedEmailClient
{
    /** @return array<string, mixed> */
    public function retrieve(string $emailId): array
    {
        $apiKey = config('services.resend.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('Resend API key is not configured.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->get('https://api.resend.com/emails/receiving/'.rawurlencode($emailId));

        $response->throw();
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Resend received-email response was invalid.');
        }

        return $payload;
    }
}