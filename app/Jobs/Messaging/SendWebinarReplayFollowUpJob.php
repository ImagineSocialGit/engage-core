<?php

namespace App\Jobs\Messaging;

use App\Data\WebinarMessageData;
use App\Models\WebinarRegistration;
use App\Models\WebinarScheduledMessage;
use App\Services\Messaging\EmailMessagingService;
use App\Services\Messaging\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWebinarReplayFollowUpJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $registrationId,
        public int $scheduledMessageId
    ) {}

    public function handle(
        EmailMessagingService $emailMessagingService,
        SmsMessagingService $smsMessagingService,
    ): void {
        $scheduled = WebinarScheduledMessage::query()->find($this->scheduledMessageId);

        $registration = WebinarRegistration::query()
            ->with(['lead', 'webinar'])
            ->find($this->registrationId);

        if (! $registration || ! $scheduled) {
            return;
        }

        $data = WebinarMessageData::fromRegistration($registration);

        try {
            if ($scheduled->channel === 'email') {
                $emailMessagingService->sendPostWebinarFollowUp($data, 'replay');
            }

            if ($scheduled->channel === 'sms') {
                $smsMessagingService->sendPostWebinarFollowUp($data, 'replay');
            }

            $scheduled->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            $scheduled->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}