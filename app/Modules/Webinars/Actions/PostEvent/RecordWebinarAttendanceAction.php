<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecordWebinarAttendanceAction
{
    public function __construct(
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
    ) {}

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
            ->with(['contact', 'webinar', 'webinar.webinarSeries'])
            ->where('status', '!=', 'cancelled')
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

            $matchedRegistrationIds[] = $registration->id;

            $this->recordAttendedRegistration(
                registration: $registration,
                provider: $provider,
                match: $match,
            );
        }

        $registrations
            ->reject(fn (WebinarRegistration $registration) => in_array($registration->id, $matchedRegistrationIds, true))
            ->each(function (WebinarRegistration $registration) use ($provider): void {
                $this->recordMissedRegistration(
                    registration: $registration,
                    provider: $provider,
                );
            });
    }

    private function recordAttendedRegistration(
        WebinarRegistration $registration,
        string $provider,
        WebinarAttendanceRecord $match,
    ): void {
        if ($registration->attended_at !== null) {
            return;
        }

        DB::transaction(function () use ($registration, $provider, $match): void {
            $recordedAt = now();
            $attendedAt = $this->attendedAt($match->joinTime);

            $meta = $registration->meta ?? [];

            $meta['attendance'] = [
                'provider' => $provider,
                'status' => $match->status ?: 'attended',
                'duration' => $match->duration,
                'join_time' => $this->dateTimeString($match->joinTime),
                'leave_time' => $this->dateTimeString($match->leaveTime),
                'recorded_at' => $recordedAt->toIso8601String(),
                'raw' => $match->raw,
            ];

            $registration->forceFill([
                'attended_at' => $attendedAt,
                'meta' => $meta,
            ])->save();

            DB::afterCommit(function () use ($registration, $provider, $match, $attendedAt): void {
                $registration = $registration->fresh([
                    'contact',
                    'webinar',
                    'webinar.webinarSeries',
                ]);

                if (! $registration) {
                    return;
                }

                $this->emitWebinarAutomationEvent->forRegistration(
                    eventKey: 'webinar.attended',
                    registration: $registration,
                    occurredAt: $attendedAt,
                    payload: [
                        'attendance' => [
                            'provider' => $provider,
                            'status' => $match->status ?: 'attended',
                            'duration' => $match->duration,
                            'join_time' => $this->dateTimeString($match->joinTime),
                            'leave_time' => $this->dateTimeString($match->leaveTime),
                        ],
                    ],
                );
            });
        });
    }

    private function recordMissedRegistration(
        WebinarRegistration $registration,
        string $provider,
    ): void {
        if ($registration->attended_at !== null) {
            return;
        }

        if (data_get($registration->meta, 'attendance.status') === 'missed') {
            return;
        }

        DB::transaction(function () use ($registration, $provider): void {
            $recordedAt = now();

            $meta = $registration->meta ?? [];

            $meta['attendance'] = [
                'provider' => $provider,
                'status' => 'missed',
                'recorded_at' => $recordedAt->toIso8601String(),
            ];

            $registration->forceFill([
                'meta' => $meta,
            ])->save();

            DB::afterCommit(function () use ($registration, $provider, $recordedAt): void {
                $registration = $registration->fresh([
                    'contact',
                    'webinar',
                    'webinar.webinarSeries',
                ]);

                if (! $registration) {
                    return;
                }

                $this->emitWebinarAutomationEvent->forRegistration(
                    eventKey: 'webinar.missed',
                    registration: $registration,
                    occurredAt: $recordedAt,
                    payload: [
                        'attendance' => [
                            'provider' => $provider,
                            'status' => 'missed',
                        ],
                    ],
                );
            });
        });
    }

    protected function matchesRegistration(
        mixed $registrationRegistrantId,
        ?string $registrationEmail,
        WebinarAttendanceRecord $attendanceRecord,
    ): bool {
        if (filled($registrationRegistrantId) && filled($attendanceRecord->registrantId)) {
            return (string) $registrationRegistrantId === (string) $attendanceRecord->registrantId;
        }

        if (filled($registrationEmail) && filled($attendanceRecord->email)) {
            return mb_strtolower(trim($attendanceRecord->email)) === $registrationEmail;
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