<?php

namespace App\Actions\Webinars;

use App\Models\Webinar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecordZoomAttendanceAction
{
    public function execute(string $webinarId, Collection $participants): void
    {
        $webinar = Webinar::query()
            ->where('external_id', (string) $webinarId)
            ->first();

        if (! $webinar) {
            return;
        }

        $registrations = $webinar->registrations()->get();

        $participantMatches = $participants
            ->map(function (array $participant) {
                return [
                    'registrant_id' => $participant['registrant_id'] ?? null,
                    'email' => isset($participant['email']) && filled($participant['email'])
                        ? mb_strtolower(trim($participant['email']))
                        : null,
                    'join_time' => $participant['join_time'] ?? null,
                    'leave_time' => $participant['leave_time'] ?? null,
                    'duration' => $participant['duration'] ?? null,
                    'raw' => $participant['raw'] ?? $participant,
                ];
            });

        $matchedRegistrationIds = [];

        foreach ($registrations as $registration) {
            $registrationRegistrantId = data_get($registration->meta, 'zoom.registrant_id');
            $registrationEmail = filled($registration->email)
                ? mb_strtolower(trim($registration->email))
                : null;

            $match = $participantMatches->first(function (array $participant) use ($registrationRegistrantId, $registrationEmail) {
                if (filled($registrationRegistrantId) && filled($participant['registrant_id'])) {
                    return (string) $participant['registrant_id'] === (string) $registrationRegistrantId;
                }

                if (filled($registrationEmail) && filled($participant['email'])) {
                    return $participant['email'] === $registrationEmail;
                }

                return false;
            });

            if (! $match) {
                continue;
            }

            $meta = $registration->meta ?? [];
            $meta['attendance'] = [
                'provider' => 'zoom',
                'duration' => $match['duration'],
                'join_time' => $match['join_time']?->toIso8601String(),
                'leave_time' => $match['leave_time']?->toIso8601String(),
                'recorded_at' => now()->toIso8601String(),
                'raw' => $match['raw'],
            ];

            $registration->forceFill([
                'attended_at' => $registration->attended_at ?? ($match['join_time'] instanceof Carbon ? $match['join_time'] : now()),
                'meta' => $meta,
            ])->save();

            $matchedRegistrationIds[] = $registration->id;
        }

        $webinar->registrations()
            ->whereNotIn('id', $matchedRegistrationIds)
            ->whereNull('attended_at')
            ->get()
            ->each(function ($registration) {
                $meta = $registration->meta ?? [];
                $meta['attendance'] = [
                    'provider' => 'zoom',
                    'status' => 'missed',
                    'recorded_at' => now()->toIso8601String(),
                ];

                $registration->forceFill([
                    'meta' => $meta,
                ])->save();
            });
    }
}