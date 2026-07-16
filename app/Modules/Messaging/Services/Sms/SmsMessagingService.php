<?php

namespace App\Modules\Messaging\Services\Sms;

use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Services\DevMessageSink;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;

class SmsMessagingService
{
    public function __construct(
        private readonly DevMessageSink $devMessageSink,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly SmsProviderManager $smsProviderManager,
        private readonly SmsSendGuard $smsSendGuard,
    ) {}

    public function send(SmsMessage $payload): MessageSendResult
    {
        if (! config('sms.enabled')) {
            return MessageSendResult::skipped(
                reasonCode: 'sms_disabled',
                reason: 'SMS delivery is disabled.',
            );
        }

        $destination = trim($payload->to());

        if ($destination === '') {
            return MessageSendResult::skipped(
                reasonCode: 'sms_destination_missing',
                reason: 'SMS destination is missing.',
            );
        }

        $to = $this->phoneNumberNormalizer->normalize($destination);

        if (! $to) {
            return MessageSendResult::skipped(
                reasonCode: 'sms_destination_invalid',
                reason: 'SMS destination is invalid.',
            );
        }

        $sourceIp = $payload->sourceIp();
        $message = $payload->message();
        $kind = $payload->kind();
        $purpose = $payload->purpose();
        $decision = $this->smsSendGuard->decision($to, $message, $kind, $sourceIp);

        if (! $decision->allowed) {
            return MessageSendResult::skipped(
                reasonCode: $decision->reasonCode ?? 'sms_guard_denied',
                reason: $decision->reason ?? 'SMS send guard denied delivery.',
            );
        }

        if (app()->environment('local')) {
            $provider = (string) config('sms.provider', 'twilio');

            $this->devMessageSink->store('sms', [
                ...$payload->devPayload(),
                'provider' => $provider,
                'normalized_phone' => $to,
            ]);

            $this->smsSendGuard->record($to, $message, $kind, $sourceIp);

            return MessageSendResult::sent(provider: 'dev_sink', meta: [
                'configured_provider' => $provider,
                'normalized_phone' => $to,
            ]);
        }

        $result = $this->smsProviderManager
            ->defaultProvider()
            ->send($to, $message, [
                'kind' => $kind,
                'purpose' => $purpose,
                'source_ip' => $sourceIp,
            ]);

        if ($result->isSent()) {
            $this->smsSendGuard->record($to, $message, $kind, $sourceIp);
        }

        return $result;
    }
}
