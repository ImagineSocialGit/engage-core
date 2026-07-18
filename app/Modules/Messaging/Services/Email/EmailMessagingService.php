<?php

namespace App\Modules\Messaging\Services\Email;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Services\DevMessageSink;

class EmailMessagingService
{
    public function __construct(
        private readonly DevMessageSink $devMessageSink,
        private readonly EmailProviderManager $emailProviderManager,
    ) {}

    public function send(EmailMessage $payload): MessageSendResult
    {
        $to = trim($payload->to());

        if ($to === '') {
            return MessageSendResult::skipped(
                reasonCode: 'email_destination_missing',
                reason: 'Email destination is missing.',
            );
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('email', $payload->devPayload());

            return MessageSendResult::sent(provider: 'dev_sink');
        }

        return $this->emailProviderManager->provider()->send(
            message: $payload,
            idempotencyKey: $this->providerIdempotencyKey($payload),
        );
    }

    private function providerIdempotencyKey(EmailMessage $payload): ?string
    {
        $key = data_get($payload, 'meta.delivery.provider_idempotency_key')
            ?? data_get($payload->devPayload(), 'meta.delivery.provider_idempotency_key');

        return is_string($key) && trim($key) !== ''
            ? trim($key)
            : null;
    }
}