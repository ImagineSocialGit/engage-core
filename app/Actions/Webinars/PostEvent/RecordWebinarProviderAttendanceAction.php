<?php

namespace App\Actions\Webinars\PostEvent;

use App\Contracts\Webinars\WebinarProvider;
use App\Models\Webinar;

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

        $this->recordWebinarAttendanceAction->execute(
            webinar: $webinar,
            provider: $provider->key(),
            attendanceRecords: collect($provider->listAttendanceRecords($webinar)),
        );

        $webinar->forceFill([
            'meta' => array_replace_recursive($webinar->fresh()->meta ?? [], [
                'normalized' => [
                    'post_event' => [
                        'attendance_recorded_at' => now()->toIso8601String(),
                    ],
                ],
            ]),
        ])->save();

        return true;
    }
}