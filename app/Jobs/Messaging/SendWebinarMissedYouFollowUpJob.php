<?php

namespace App\Jobs\Messaging;

use App\Data\WebinarMessageData;
use App\Models\WebinarRegistration;
use App\Services\Messaging\EmailMessagingService;
use App\Services\Messaging\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebinarMissedYouFollowUpJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $registrationId
    ) {}

    public function handle(
        EmailMessagingService $emailMessagingService,
        SmsMessagingService $smsMessagingService,
    ): void {
        $registration = WebinarRegistration::query()
            ->with(['lead', 'webinar'])
            ->find($this->registrationId);

        if (! $registration) {
            return;
        }

        $data = WebinarMessageData::fromRegistration($registration);

        $emailMessagingService->sendPostWebinarFollowUp($data, 'missed');
        $smsMessagingService->sendPostWebinarFollowUp($data, 'missed');
    }
}