<?php

namespace App\Integrations\Messaging\Sms\Twilio;

use App\Modules\Messaging\Contracts\Sms\SmsProvider;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use RuntimeException;
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
    ): MessageSendResult {
        $purpose = $this->purposeFromMeta($meta);
        $from = $this->fromForPurpose($purpose);
        $sent = $this->client->messages->create($to, [
            'from' => $from,
            'body' => $message,
        ]);
        $providerMessageId = is_string($sent->sid ?? null)
            ? trim((string) $sent->sid)
            : null;

        return MessageSendResult::sent(
            provider: $this->provider(),
            providerMessageId: $providerMessageId !== '' ? $providerMessageId : null,
        );
    }

    private function purposeFromMeta(array $meta): string
    {
        $purpose = $meta['purpose'] ?? null;

        if (! is_string($purpose) || trim($purpose) === '') {
            throw new RuntimeException('Twilio SMS purpose is not configured.');
        }

        return trim($purpose);
    }

    private function fromForPurpose(string $purpose): string
    {
        $from = config("sms.providers.twilio.from.{$purpose}")
            ?: config("sms.from.{$purpose}");

        if (! is_string($from) || trim($from) === '') {
            throw new RuntimeException("Twilio from number is not configured for purpose [{$purpose}].");
        }

        return trim($from);
    }
}
