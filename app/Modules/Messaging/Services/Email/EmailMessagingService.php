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

        return $this->emailProviderManager->provider()->send($payload);
    }
}
