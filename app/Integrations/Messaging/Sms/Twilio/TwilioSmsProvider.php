<?php

namespace App\Integrations\Messaging\Sms\Twilio;

use App\Contracts\Messaging\SmsProvider;
use Twilio\Rest\Client;

class TwilioSmsProvider implements SmsProvider
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function provider(): string
    {
        return 'twilio';
    }

    public function send(
        string $to,
        string $message,
        array $meta = [],
    ): void {
        $this->client->messages->create($to, [
            'from' => config('sms.providers.twilio.from'),
            'body' => $message,
        ]);
    }
}