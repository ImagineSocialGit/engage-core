<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\SmsMessagePayload;

class SmsMessagingService
{
    public function __construct(
        private readonly DevMessageSink $devMessageSink,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly SmsProviderManager $smsProviderManager,
        private readonly SmsSendGuard $smsSendGuard,
    ) {}

    public function send(SmsMessagePayload $payload): void
    {
        if (! config('sms.enabled')) {
            return;
        }

        if (! $payload->to()) {
            return;
        }

        $to = $this->phoneNumberNormalizer->normalize($payload->to());

        if (! $to) {
            return;
        }

        $sourceIp = $payload->sourceIp();
        $message = $payload->message();
        $kind = $payload->kind();

        if (! $this->smsSendGuard->allows($to, $message, $kind, $sourceIp)) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('sms', [
                ...$payload->devPayload(),
                'provider' => config('sms.provider', 'twilio'),
                'normalized_phone' => $to,
            ]);

            $this->smsSendGuard->record($to, $message, $kind, $sourceIp);

            return;
        }

        $this->smsProviderManager
            ->defaultProvider()
            ->send($to, $message, [
                'kind' => $kind,
                'source_ip' => $sourceIp,
            ]);

        $this->smsSendGuard->record($to, $message, $kind, $sourceIp);
    }
}