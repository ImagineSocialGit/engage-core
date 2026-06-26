<?php

namespace App\Modules\Messaging\Services\Sms;

use App\Modules\Messaging\Contracts\Sms\SmsProvider;
use App\Integrations\Messaging\Sms\Telnyx\TelnyxSmsProvider;
use App\Integrations\Messaging\Sms\Twilio\TwilioSmsProvider;
use InvalidArgumentException;

class SmsProviderManager
{
    /**
     * @param array<string, SmsProvider> $providers
     */
    public function __construct(
        private readonly array $providers,
    ) {}

    public static function default(): self
    {
        return new self([
            'twilio' => app(TwilioSmsProvider::class),
            'telnyx' => app(TelnyxSmsProvider::class),
        ]);
    }

    public function defaultProvider(): SmsProvider
    {
        return $this->resolve(config('sms.provider', 'twilio'));
    }

    public function resolve(string $provider): SmsProvider
    {
        $provider = strtolower(trim($provider));

        if (! isset($this->providers[$provider])) {
            throw new InvalidArgumentException("Unsupported SMS provider [{$provider}].");
        }

        return $this->providers[$provider];
    }
}