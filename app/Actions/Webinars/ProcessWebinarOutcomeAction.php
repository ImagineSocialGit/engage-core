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
        $registration->loadMissing('webinar');

        if (data_get($registration->meta, 'post_webinar_routed_at')) {
            return;
        }

        if ($registration->attended_at) {
            $this->dispatchFollowUpMessages($registration, 'post_replay');

            return;
        }

        $this->dispatchFollowUpMessages($registration, 'post_missed');
    }

    protected function dispatchFollowUpMessages(
        WebinarRegistration $registration,
        string $messageType
    ): void {

        $meta = $registration->meta ?? [];
        $meta['post_webinar_routed_at'] = now()->toIso8601String();

        $registration->forceFill([
            'meta' => $meta,
        ])->save();

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
                ->onQueue(config('webinars.queues.followups'));

            return;
        }

        SendWebinarMissedYouFollowUpJob::dispatch($registration->id, $scheduled->id)
            ->onQueue(config('webinars.queues.followups'));
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
                ->onQueue(config('webinars.queues.followups'));

            return;
        }

        SendWebinarMissedYouFollowUpJob::dispatch($registration->id, $scheduled->id)
            ->onQueue(config('webinars.queues.followups'));
    }
}
