<?php

namespace App\Jobs\Messaging;

use App\Data\WebinarMessageData;
use App\Services\Messaging\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebinarConfirmationSmsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload
    ) {}

    public function handle(SmsMessagingService $smsMessagingService): void
    {
        file_put_contents(
            storage_path('app/sms-job-ran.txt'),
            now() . "\n",
            FILE_APPEND
        );

        $smsMessagingService->sendRegistrationConfirmation(
            WebinarMessageData::fromArray($this->payload)
        );
    }
}