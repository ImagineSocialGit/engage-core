<?php

namespace App\Modules\Messaging\Services\Email;

use App\Modules\Messaging\Contracts\Email\EmailProvider;
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