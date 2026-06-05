<?php

namespace App\Services\Messaging\Email;

use App\Contracts\Messaging\Email\EmailMessage;
use App\Services\Messaging\DevMessageSink;

class EmailMessagingService
{
    public function __construct(
        private readonly DevMessageSink $devMessageSink,
        private readonly EmailProviderManager $emailProviderManager,
    ) {}

    public function send(EmailMessage $payload): void
    {
        if (! $payload->to()) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('email', $payload->devPayload());

            return;
        }

        $this->emailProviderManager->provider()->send($payload);
    }
}