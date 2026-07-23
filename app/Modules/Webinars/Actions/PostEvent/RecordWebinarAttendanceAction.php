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

class RecordWebinarAttendanceAction
{
    public function __construct(
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly WebinarStateCanonicalizer $stateCanonicalizer,
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
                matchedBy: $this->matchMethod(
                    registrationRegistrantId: $registrationRegistrantId,
                    registrationEmail: $registrationEmail,
                    attendanceRecord: $match,
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
        if ($registration->attended_at !== null && $registration->status === 'attended') {
            return;
        }

        DB::transaction(function () use ($registration, $provider, $match, $matchedBy): void {
            $recordedAt = now();
            $attendedAt = $this->attendedAt($match->joinTime);

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