<?php

namespace App\Jobs\Messaging;

use App\Data\WebinarMessageData;
use App\Services\Messaging\EmailMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebinarReminderEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload
    ) {}

    public function handle(EmailMessagingService $emailMessagingService): void
    {
        $emailMessagingService->sendReminder(
            WebinarMessageData::fromArray($this->payload),
            $this->payload['message_type']
        );
    }
}