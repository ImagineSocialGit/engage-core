<?php

namespace App\Services\Messaging\Sms;

use App\Contracts\Messaging\Sms\SmsWebhookHandler;
use App\Integrations\Messaging\Sms\Telnyx\TelnyxWebhookHandler;
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