<?php

namespace App\Jobs\Messaging;

use App\Data\WebinarMessageData;
use App\Services\Messaging\EmailMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebinarConfirmationEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload
    ) {}

    public function handle(EmailMessagingService $emailMessagingService): void
    {
        $emailMessagingService->sendRegistrationConfirmation(
            WebinarMessageData::fromArray($this->payload)
        );
    }
}