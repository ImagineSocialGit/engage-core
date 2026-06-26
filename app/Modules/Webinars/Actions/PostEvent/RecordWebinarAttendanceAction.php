<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Models\Webinar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecordWebinarAttendanceAction
{
    public function execute(
        Webinar $webinar,
        string $provider,
        Collection $attendanceRecords,
    ): void {

        $attendanceRecords = $attendanceRecords
            ->map(fn (WebinarAttendanceRecord|array $record) => $record instanceof WebinarAttendanceRecord
                ? $record
                : WebinarAttendanceRecord::fromArray($record)
            )
            ->values();

        $registrations = $webinar->registrations()
            ->with('contact')
            ->get();

        $matchedRegistrationIds = [];

        foreach ($registrations as $registration) {
            $registrationRegistrantId = data_get($registration->meta, 'provider.data.registrant_id')
                ?? data_get($registration->meta, 'provider.registrant_id');

            $registrationEmail = filled($registration->contact?->email)
                ? mb_strtolower(trim($registration->contact->email))
                : null;

            $match = $attendanceRecords->first(
                fn (WebinarAttendanceRecord $record) => $this->matchesRegistration(
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
                'status' => $match->status,
                'duration' => $match->duration,
                'join_time' => $this->dateTimeString($match->joinTime),
                'leave_time' => $this->dateTimeString($match->leaveTime),
                'recorded_at' => now()->toIso8601String(),
                'raw' => $match->raw,
            ];

            $registration->forceFill([
                'attended_at' => $registration->attended_at
                    ?? $this->attendedAt($match->joinTime),
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
    }

    protected function matchesRegistration(
        mixed $registrationRegistrantId,
        ?string $registrationEmail,
        WebinarAttendanceRecord $attendanceRecord,
    ): bool {
        if (filled($registrationRegistrantId) && filled($attendanceRecord->registrantId)) {
            return (string) $attendanceRecord->registrantId === (string) $registrationRegistrantId;
        }

        if (filled($registrationEmail) && filled($attendanceRecord->email)) {
            return $attendanceRecord->email === $registrationEmail;
        }

        return false;
    }

    protected function attendedAt(?CarbonInterface $joinTime): CarbonInterface
    {
        return $joinTime ?: now();
    }

    protected function dateTimeString(?CarbonInterface $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::instance($value)->toIso8601String();
    }
}