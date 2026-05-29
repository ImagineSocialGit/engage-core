<?php

namespace App\Actions\Webinars;

use App\Jobs\Webinars\RoutePostWebinarRegistrationJob;
use App\Models\Webinar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecordWebinarAttendanceAction
{
    public function execute(
        string $provider,
        string $externalWebinarId,
        Collection $attendanceRecords,
    ): void {
        $webinar = Webinar::query()
            ->where('platform', $provider)
            ->where('external_id', $externalWebinarId)
            ->first();

        if (! $webinar) {
            return;
        }

        $registrations = $webinar->registrations()
            ->with('lead')
            ->get();

        $matchedRegistrationIds = [];

        foreach ($registrations as $registration) {
            $registrationRegistrantId = data_get($registration->meta, 'provider.data.registrant_id')
                ?? data_get($registration->meta, 'provider.registrant_id');

            $registrationEmail = filled($registration->lead?->email)
                ? mb_strtolower(trim($registration->lead->email))
                : null;

            $match = $attendanceRecords->first(
                fn (array $record) => $this->matchesRegistration(
                    registrationRegistrantId: $registrationRegistrantId,
                    registrationEmail: $registrationEmail,
                    attendanceRecord: $record,
                )
            );

            if (! $match) {
                continue;
            }

            $meta = $registration->meta ?? [];

            $meta['attendance'] = [
                'provider' => $provider,
                'status' => $match['status'] ?? 'attended',
                'duration' => $match['duration'] ?? null,
                'join_time' => $this->dateTimeString($match['join_time'] ?? null),
                'leave_time' => $this->dateTimeString($match['leave_time'] ?? null),
                'recorded_at' => now()->toIso8601String(),
                'raw' => $match['raw'] ?? $match,
            ];

            $registration->forceFill([
                'attended_at' => $registration->attended_at
                    ?? $this->attendedAt($match['join_time'] ?? null),
                'meta' => $meta,
            ])->save();

            $matchedRegistrationIds[] = $registration->id;
        }

        $webinar->registrations()
            ->whereNotIn('id', $matchedRegistrationIds)
            ->whereNull('attended_at')
            ->get()
            ->each(function ($registration) use ($provider) {
                $meta = $registration->meta ?? [];

                $meta['attendance'] = [
                    'provider' => $provider,
                    'status' => 'missed',
                    'recorded_at' => now()->toIso8601String(),
                ];

                $registration->forceFill([
                    'meta' => $meta,
                ])->save();
            });

        $webinar->registrations()
            ->pluck('id')
            ->each(function ($registrationId) {
                RoutePostWebinarRegistrationJob::dispatch($registrationId)
                    ->onQueue('notifications');
            });
    }

    protected function matchesRegistration(
        mixed $registrationRegistrantId,
        ?string $registrationEmail,
        array $attendanceRecord,
    ): bool {
        $attendanceRegistrantId = $attendanceRecord['registrant_id'] ?? null;
        $attendanceEmail = filled($attendanceRecord['email'] ?? null)
            ? mb_strtolower(trim($attendanceRecord['email']))
            : null;

        if (filled($registrationRegistrantId) && filled($attendanceRegistrantId)) {
            return (string) $attendanceRegistrantId === (string) $registrationRegistrantId;
        }

        if (filled($registrationEmail) && filled($attendanceEmail)) {
            return $attendanceEmail === $registrationEmail;
        }

        return false;
    }

    protected function attendedAt(mixed $joinTime): Carbon
    {
        return $joinTime instanceof Carbon ? $joinTime : now();
    }

    protected function dateTimeString(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        return null;
    }
}
