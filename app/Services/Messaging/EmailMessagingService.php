<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\EmailMessagePayload;
use Illuminate\Support\Facades\Mail;

class EmailMessagingService
{
    public function __construct(
        private readonly DevMessageSink $devMessageSink,
    ) {}

    public function send(EmailMessagePayload $payload): void
    {
        if (! $payload->to()) {
            return;
        }

        if (app()->environment('local')) {
            $this->devMessageSink->store('email', $payload->devPayload());

            return;
        }

        Mail::to($payload->to())->send($payload->mailable());
    }
}