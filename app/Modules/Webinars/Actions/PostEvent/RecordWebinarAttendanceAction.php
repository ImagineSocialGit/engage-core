<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarStateCanonicalizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecordWebinarAttendanceAction
{
    public function __construct(
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly WebinarStateCanonicalizer $stateCanonicalizer,
        private readonly EnsureMissedWebinarFutureAvailabilitySubscriptionAction
            $ensureFutureAvailabilitySubscription,
    ) {}

    public function execute(
        Webinar $webinar,
        string $provider,
        Collection $attendanceRecords,
        bool $finalizeMissed = true,
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

            $matches = $attendanceRecords
                ->filter(
                    fn (WebinarAttendanceRecord $record): bool => $this->matchesRegistration(
                        registrationRegistrantId: $registrationRegistrantId,
                        registrationEmail: $registrationEmail,
                        attendanceRecord: $record,
                    ),
                )
                ->values();

            if ($matches->isEmpty()) {
                continue;
            }

            $match = $this->aggregateAttendanceRecords($matches);

            if (! $match instanceof WebinarAttendanceRecord) {
                continue;
            }

            $matchedRegistrationIds[] = $registration->id;

            $this->recordAttendedRegistration(
                registration: $registration,
                provider: $provider,
                match: $match,
                matchedBy: $this->aggregateMatchMethod(
                    registrationRegistrantId: $registrationRegistrantId,
                    registrationEmail: $registrationEmail,
                    attendanceRecords: $matches,
                ),
            );
        }

        if (! $finalizeMissed) {
            return;
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
        string $matchedBy,
    ): void {
        $wasAlreadyAttended = $registration->attended_at !== null
            && $registration->status === 'attended';

        DB::transaction(function () use (
            $registration,
            $provider,
            $match,
            $matchedBy,
            $wasAlreadyAttended,
        ): void {
            $recordedAt = now();
            $attendedAt = $this->attendedAt(
                joinTime: $match->joinTime,
                existingAttendedAt: $registration->attended_at,
            );

            $meta = is_array($registration->meta)
                ? $registration->meta
                : [];

            $meta['attendance'] = $this->stateCanonicalizer->attendance([
                'provider' => $provider,
                'status' => $match->status ?: 'attended',
                'duration' => $match->duration,
                'join_time' => $this->dateTimeString($match->joinTime),
                'leave_time' => $this->dateTimeString($match->leaveTime),
                'recorded_at' => $recordedAt->toIso8601String(),
                'provider_registrant_id' => $match->registrantId,
                'matched_by' => $matchedBy,
            ]);

            $registration->forceFill([
                'status' => 'attended',
                'attended_at' => $attendedAt,
                'meta' => $meta,
            ])->save();

            if ($wasAlreadyAttended) {
                return;
            }

            $this->emitWebinarAutomationEvent->forRegistration(
                eventKey: config('webinars.post_event.automation_events.attended.event_key', 'webinar.attended'),
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
    }

    private function recordMissedRegistration(
        WebinarRegistration $registration,
        string $provider,
    ): void {
        if ($registration->attended_at !== null) {
            return;
        }

        if ($registration->status === 'missed' && data_get($registration->meta, 'attendance.status') === 'missed') {
            $this->ensureFutureAvailabilitySubscription($registration);

            return;
        }

        DB::transaction(function () use ($registration, $provider): void {
            $recordedAt = now();

            $meta = is_array($registration->meta)
                ? $registration->meta
                : [];

            $meta['attendance'] = $this->stateCanonicalizer->attendance([
                'provider' => $provider,
                'status' => 'missed',
                'recorded_at' => $recordedAt->toIso8601String(),
            ]);

            $registration->forceFill([
                'status' => 'missed',
                'meta' => $meta,
            ])->save();

            $this->emitWebinarAutomationEvent->forRegistration(
                eventKey: config('webinars.post_event.automation_events.missed.event_key', 'webinar.missed'),
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

        $this->ensureFutureAvailabilitySubscription($registration);
    }

    private function ensureFutureAvailabilitySubscription(
        WebinarRegistration $registration,
    ): void {
        try {
            $this->ensureFutureAvailabilitySubscription->execute(
                $registration->fresh([
                    'contact',
                    'webinar',
                    'webinar.webinarSeries',
                ]) ?? $registration,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function aggregateAttendanceRecords(
        Collection $attendanceRecords,
    ): ?WebinarAttendanceRecord {
        $attendanceRecords = $attendanceRecords
            ->filter(fn (mixed $record): bool => $record instanceof WebinarAttendanceRecord)
            ->values();

        if ($attendanceRecords->isEmpty()) {
            return null;
        }

        $registrantId = $attendanceRecords
            ->map(fn (WebinarAttendanceRecord $record): ?string => filled($record->registrantId)
                ? (string) $record->registrantId
                : null
            )
            ->filter()
            ->unique()
            ->values()
            ->first();

        $email = $attendanceRecords
            ->map(fn (WebinarAttendanceRecord $record): ?string => filled($record->email)
                ? mb_strtolower(trim((string) $record->email))
                : null
            )
            ->filter()
            ->unique()
            ->values()
            ->first();

        return new WebinarAttendanceRecord(
            registrantId: is_string($registrantId) ? $registrantId : null,
            email: is_string($email) ? $email : null,
            status: 'attended',
            duration: $this->aggregateDuration($attendanceRecords),
            joinTime: $this->earliestJoinTime($attendanceRecords),
            leaveTime: $this->latestLeaveTime($attendanceRecords),
            raw: [],
        );
    }

    protected function aggregateDuration(Collection $attendanceRecords): ?int
    {
        $intervals = [];
        $fallbackDuration = 0;
        $hasDurationEvidence = false;

        foreach ($attendanceRecords as $record) {
            if (! $record instanceof WebinarAttendanceRecord) {
                continue;
            }

            if ($record->joinTime !== null && $record->leaveTime !== null) {
                $start = $record->joinTime->getTimestamp();
                $end = $record->leaveTime->getTimestamp();

                if ($end > $start) {
                    $intervals[] = [$start, $end];
                    $hasDurationEvidence = true;

                    continue;
                }
            }

            if ($record->duration !== null) {
                $fallbackDuration += max(0, $record->duration);
                $hasDurationEvidence = true;
            }
        }

        if ($intervals === []) {
            return $hasDurationEvidence ? $fallbackDuration : null;
        }

        usort(
            $intervals,
            static fn (array $left, array $right): int =>
                $left[0] <=> $right[0] ?: $left[1] <=> $right[1],
        );

        [$currentStart, $currentEnd] = $intervals[0];
        $mergedDuration = 0;

        foreach (array_slice($intervals, 1) as [$start, $end]) {
            if ($start <= $currentEnd) {
                $currentEnd = max($currentEnd, $end);

                continue;
            }

            $mergedDuration += $currentEnd - $currentStart;
            $currentStart = $start;
            $currentEnd = $end;
        }

        $mergedDuration += $currentEnd - $currentStart;

        return $mergedDuration + $fallbackDuration;
    }

    protected function earliestJoinTime(
        Collection $attendanceRecords,
    ): ?CarbonInterface {
        return $attendanceRecords
            ->map(fn (WebinarAttendanceRecord $record): ?CarbonInterface => $record->joinTime)
            ->filter()
            ->sortBy(fn (CarbonInterface $value): int => $value->getTimestamp())
            ->first();
    }

    protected function latestLeaveTime(
        Collection $attendanceRecords,
    ): ?CarbonInterface {
        return $attendanceRecords
            ->map(fn (WebinarAttendanceRecord $record): ?CarbonInterface => $record->leaveTime)
            ->filter()
            ->sortByDesc(fn (CarbonInterface $value): int => $value->getTimestamp())
            ->first();
    }

    protected function matchesRegistration(
        mixed $registrationRegistrantId,
        ?string $registrationEmail,
        WebinarAttendanceRecord $attendanceRecord,
    ): bool {
        return $this->matchMethod(
            registrationRegistrantId: $registrationRegistrantId,
            registrationEmail: $registrationEmail,
            attendanceRecord: $attendanceRecord,
        ) !== null;
    }

    protected function aggregateMatchMethod(
        mixed $registrationRegistrantId,
        ?string $registrationEmail,
        Collection $attendanceRecords,
    ): string {
        $methods = $attendanceRecords
            ->map(fn (WebinarAttendanceRecord $record): ?string => $this->matchMethod(
                registrationRegistrantId: $registrationRegistrantId,
                registrationEmail: $registrationEmail,
                attendanceRecord: $record,
            ))
            ->filter()
            ->unique()
            ->values();

        if ($methods->contains('provider_registrant_id')) {
            return 'provider_registrant_id';
        }

        return 'email';
    }

    protected function matchMethod(
        mixed $registrationRegistrantId,
        ?string $registrationEmail,
        WebinarAttendanceRecord $attendanceRecord,
    ): ?string {
        if (filled($registrationRegistrantId) && filled($attendanceRecord->registrantId)) {
            return (string) $registrationRegistrantId === (string) $attendanceRecord->registrantId
                ? 'provider_registrant_id'
                : null;
        }

        if (filled($registrationEmail) && filled($attendanceRecord->email)) {
            return mb_strtolower(trim($attendanceRecord->email)) === $registrationEmail
                ? 'email'
                : null;
        }

        return null;
    }

    protected function attendedAt(
        ?CarbonInterface $joinTime,
        ?CarbonInterface $existingAttendedAt = null,
    ): CarbonInterface {
        if ($joinTime !== null && $existingAttendedAt !== null) {
            return $joinTime->getTimestamp() < $existingAttendedAt->getTimestamp()
                ? $joinTime
                : $existingAttendedAt;
        }

        return $joinTime
            ?? $existingAttendedAt
            ?? now();
    }

    protected function dateTimeString(?CarbonInterface $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::instance($value)->toIso8601String();
    }
}