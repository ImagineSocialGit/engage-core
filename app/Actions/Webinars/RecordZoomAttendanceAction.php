<?php

namespace App\Actions\Webinars;

use App\Models\WebinarRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RecordZoomAttendanceAction
{
    public function execute(array $payload): void
    {
        $event = $payload['event'] ?? null;
        $object = $payload['payload']['object'] ?? [];
        $participant = $object['participant'] ?? [];

        if (! in_array($event, [
            'meeting.participant_joined',
            'webinar.participant_joined',
        ], true)) {
            return;
        }

        $webinarUuid = $object['uuid'] ?? null;
        $webinarId = $object['id'] ?? null;
        $participantEmail = $participant['email'] ?? null;

        if (! $participantEmail) {
            return;
        }

        $registration = WebinarRegistration::query()
            ->with('webinar')
            ->where(function ($query) use ($participantEmail) {
                $query->where('email', $participantEmail)
                    ->orWhereHas('lead', fn ($q) => $q->where('email', $participantEmail));
            })
            ->when($webinarId, function ($query) use ($webinarId) {
                $query->whereHas('webinar', function ($q) use ($webinarId) {
                    $q->where('meta->zoom_meeting_id', (string) $webinarId)
                      ->orWhere('meta->zoom_meeting_id', (int) $webinarId);
                });
            })
            ->latest('registered_at')
            ->first();

        if (! $registration) {
            return;
        }

        if (! $registration->attended_at) {
            $registration->update([
                'attended_at' => Carbon::now(),
                'meta' => array_merge($registration->meta ?? [], [
                    'zoom' => [
                        'last_event' => $event,
                        'meeting_uuid' => $webinarUuid,
                        'meeting_id' => $webinarId,
                        'participant_email' => $participantEmail,
                        'recorded_at' => Carbon::now()->toIso8601String(),
                    ],
                ]),
            ]);
        }
    }
}