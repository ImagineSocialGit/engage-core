<?php

namespace App\Services\Messaging\Email;

use App\Contracts\Messaging\Email\EmailProvider;
use InvalidArgumentException;

class EmailProviderManager
{
    public function provider(): EmailProvider
    {
        $provider = config('messaging.email.provider');

        $providerClass = config(
            "messaging.email.providers.{$provider}.provider"
        );

        if (! is_string($providerClass)) {
            throw new InvalidArgumentException(
                "Email provider [{$provider}] is not configured."
            );
        }

        $instance = app($providerClass);

        if (! $instance instanceof EmailProvider) {
            throw new InvalidArgumentException(
                "[{$providerClass}] must implement EmailProvider."
            );
        }

        return $instance;
    }
}