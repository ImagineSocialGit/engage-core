<?php

namespace App\Services\Messaging\Email;

use App\Contracts\Messaging\Email\EmailWebhookHandler;
use InvalidArgumentException;

class EmailWebhookHandlerResolver
{
    public function resolve(string $provider): EmailWebhookHandler
    {
        $handler = config(
            "messaging.email.providers.{$provider}.webhook_handler"
        );

        if (! is_string($handler)) {
            throw new InvalidArgumentException(
                "Email webhook handler [{$provider}] is not configured."
            );
        }

        $instance = app($handler);

        if (! $instance instanceof EmailWebhookHandler) {
            throw new InvalidArgumentException(
                "[{$handler}] must implement EmailWebhookHandler."
            );
        }

        return $instance;
    }

    public static function default(): self
    {
        return app(self::class);
    }
}