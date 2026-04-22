<?php

namespace App\Actions\Webinars;

use App\Jobs\Messaging\SendWebinarMissedYouFollowUpJob;
use App\Jobs\Messaging\SendWebinarReplayFollowUpJob;
use App\Models\WebinarRegistration;
use App\Models\WebinarScheduledMessage;

class ProcessWebinarOutcomeAction
{
    public function execute(WebinarRegistration $registration): void
    {
        $registration->loadMissing(['lead', 'webinar']);

        if ($registration->attended_at && $registration->lead?->converted_at) {
            return;
        }

        if ($registration->attended_at && ! $registration->lead?->converted_at) {
            $this->dispatchFollowUpMessages($registration, 'post_replay');

            return;
        }

        if (! $registration->attended_at) {
            $this->dispatchFollowUpMessages($registration, 'post_missed');

            return;
        }
    }

    protected function dispatchFollowUpMessages(
        WebinarRegistration $registration,
        string $messageType
    ): void {
        $this->dispatchEmail($registration, $messageType);
        $this->dispatchSms($registration, $messageType);
    }

    protected function dispatchEmail(
        WebinarRegistration $registration,
        string $messageType
    ): void {
        $scheduled = WebinarScheduledMessage::query()->firstOrCreate(
            [
                'webinar_registration_id' => $registration->id,
                'channel' => 'email',
                'message_type' => $messageType,
            ],
            [
                'status' => 'pending',
                'send_at' => now(),
                'meta' => null,
            ]
        );

        if (! $scheduled->wasRecentlyCreated) {
            return;
        }

        if ($messageType === 'post_replay') {
            SendWebinarReplayFollowUpJob::dispatch($registration->id, $scheduled->id)
                ->onQueue('notifications');

            return;
        }

        SendWebinarMissedYouFollowUpJob::dispatch($registration->id, $scheduled->id)
            ->onQueue('notifications');
    }

    protected function dispatchSms(
        WebinarRegistration $registration,
        string $messageType
    ): void {
        $scheduled = WebinarScheduledMessage::query()->firstOrCreate(
            [
                'webinar_registration_id' => $registration->id,
                'channel' => 'sms',
                'message_type' => $messageType,
            ],
            [
                'status' => 'pending',
                'send_at' => now(),
                'meta' => null,
            ]
        );

        if (! $scheduled->wasRecentlyCreated) {
            return;
        }

        if ($messageType === 'post_replay') {
            SendWebinarReplayFollowUpJob::dispatch($registration->id, $scheduled->id)
                ->onQueue('notifications');

            return;
        }

        SendWebinarMissedYouFollowUpJob::dispatch($registration->id, $scheduled->id)
            ->onQueue('notifications');
    }
}