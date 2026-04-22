<?php

namespace App\Jobs\Messaging;

use App\Data\WebinarMessageData;
use App\Models\WebinarScheduledMessage;
use App\Services\Messaging\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWebinarReminderSmsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload
    ) {}

    public function handle(SmsMessagingService $smsMessagingService): void
    {
        $scheduled = WebinarScheduledMessage::find(
            $this->payload['scheduled_message_id'] ?? null
        );

        try {
            $smsMessagingService->sendReminder(
                WebinarMessageData::fromArray($this->payload),
                $this->payload['message_type']
            );

            if ($scheduled) {
                $scheduled->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }

        } catch (Throwable $e) {

            if ($scheduled) {
                $scheduled->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'failure_reason' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}