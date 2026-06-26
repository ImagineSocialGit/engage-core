<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Models\Webinar;
use Illuminate\Support\Collection;

class RecordWebinarProviderAttendanceAction
{
    public function __construct(
        private readonly RecordWebinarAttendanceAction $recordWebinarAttendanceAction,
    ) {}

    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        if (! config('webinars.post_event.attendance.enabled', true)) {
            return true;
        }

        if (data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at')) {
            return true;
        }

        $registrationsCount = $webinar->registrations()->count();

        $attendanceRecords = collect($provider->listAttendanceRecords($webinar))
            ->values();

        if (
            $registrationsCount > 0
            && $attendanceRecords->isEmpty()
            && $this->shouldContinueWaitingForAttendance($webinar)
        ) {
            $this->markAttendanceChecked(
                webinar: $webinar,
                records: $attendanceRecords,
                ready: false,
            );

            return false;
        }

        $this->recordWebinarAttendanceAction->execute(
            webinar: $webinar,
            provider: $provider->key(),
            attendanceRecords: $attendanceRecords,
        );

        $webinar->forceFill([
            'meta' => array_replace_recursive($webinar->fresh()->meta ?? [], [
                'normalized' => [
                    'post_event' => [
                        'attendance_checked_at' => now()->toIso8601String(),
                        'attendance_record_count' => $attendanceRecords->count(),
                        'attendance_ready' => true,
                        'attendance_recorded_at' => now()->toIso8601String(),
                    ],
                ],
            ]),
        ])->save();

        return true;
    }

    private function shouldContinueWaitingForAttendance(Webinar $webinar): bool
    {
        $minutes = (int) config(
            'webinars.post_event.attendance.empty_records_retry_for_minutes',
            15,
        );

        if ($minutes <= 0) {
            return false;
        }

        if (! $webinar->ends_at) {
            return true;
        }

        return now()->lt($webinar->ends_at->copy()->addMinutes($minutes));
    }

    private function markAttendanceChecked(
        Webinar $webinar,
        Collection $records,
        bool $ready,
    ): void {
        $webinar->forceFill([
            'meta' => array_replace_recursive($webinar->fresh()->meta ?? [], [
                'normalized' => [
                    'post_event' => [
                        'attendance_checked_at' => now()->toIso8601String(),
                        'attendance_record_count' => $records->count(),
                        'attendance_ready' => $ready,
                    ],
                ],
            ]),
        ])->save();
    }
}