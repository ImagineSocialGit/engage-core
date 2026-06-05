<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\SmsWebhookHandler;
use App\Integrations\Messaging\Sms\Telnyx\TelnyxWebhookHandler;
use App\Integrations\Messaging\Sms\Twilio\TwilioWebhookHandler;
use InvalidArgumentException;

class SmsWebhookHandlerResolver
{
    /**
     * @param array<string, SmsWebhookHandler> $handlers
     */
    public function __construct(
        private readonly array $handlers,
    ) {}

    public static function default(): self
    {
        return new self([
            'twilio' => app(TwilioWebhookHandler::class),
            'telnyx' => app(TelnyxWebhookHandler::class),
        ]);
    }

    public function resolve(string $provider): SmsWebhookHandler
    {
        $provider = strtolower(trim($provider));

        if (! isset($this->handlers[$provider])) {
            throw new InvalidArgumentException("Unsupported SMS webhook provider [{$provider}].");
        }

        return $this->handlers[$provider];
    }
}