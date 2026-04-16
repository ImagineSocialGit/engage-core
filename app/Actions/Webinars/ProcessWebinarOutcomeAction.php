<?php

namespace App\Actions\Webinars;

use App\Jobs\Messaging\SendWebinarMissedYouFollowUpJob;
use App\Jobs\Messaging\SendWebinarReplayFollowUpJob;
use App\Models\WebinarRegistration;

class ProcessWebinarOutcomeAction
{
    public function execute(WebinarRegistration $registration): void
    {
        $registration->refresh();

        // Prevent duplicate processing
        if ($registration->follow_up_status) {
            return;
        }

        // Case 1: Attended AND converted → do nothing
        if ($registration->attended_at && $registration->converted_at) {
            $registration->update([
                'follow_up_status' => 'converted',
            ]);

            return;
        }

        // Case 2: Attended but NOT converted → send replay
        if ($registration->attended_at && ! $registration->converted_at) {
            SendWebinarReplayFollowUpJob::dispatch($registration->id)
                ->onQueue('notifications');

            $registration->update([
                'follow_up_status' => 'replay_sent',
            ]);

            return;
        }

        // Case 3: Did NOT attend → send missed-you
        if (! $registration->attended_at) {
            SendWebinarMissedYouFollowUpJob::dispatch($registration->id)
                ->onQueue('notifications');

            $registration->update([
                'follow_up_status' => 'missed',
            ]);

            return;
        }
    }
}