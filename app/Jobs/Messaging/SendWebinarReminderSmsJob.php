<?php

namespace App\Jobs\Messaging;

use App\Data\WebinarMessageData;
use App\Services\Messaging\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebinarReminderSmsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload
    ) {}

    public function handle(SmsMessagingService $smsMessagingService): void
    {
        $smsMessagingService->sendReminder(
            WebinarMessageData::fromArray($this->payload),
            $this->payload['message_type']
        );
    }
}