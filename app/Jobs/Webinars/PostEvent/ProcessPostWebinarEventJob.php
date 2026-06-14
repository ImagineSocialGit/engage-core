<?php

namespace App\Jobs\Webinars\PostEvent;

use App\Actions\Webinars\PostEvent\RecordWebinarAttendanceAction;
use App\Models\Webinar;
use App\Services\Webinars\WebinarProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPostWebinarEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public function __construct(
        public string $provider,
        public string $externalWebinarId,
    ) {}

    public function handle(
        WebinarProviderManager $webinarProviderManager,
        RecordWebinarAttendanceAction $recordWebinarAttendanceAction,
    ): void {
        $provider = $webinarProviderManager->provider($this->provider);

        $webinar = Webinar::query()
            ->where('platform', $this->provider)
            ->where('external_id', $this->externalWebinarId)
            ->first();

        if (! $webinar) {
            return;
        }

        if (! data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at')) {
            $recordWebinarAttendanceAction->execute(
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
        }

        $webinar->refresh();

        foreach (config('webinars.post_event.webinar_ended', []) as $actionClass) {
            $result = app($actionClass)->execute($provider, $webinar);

            if ($result === false) {
                $this->release(300);

                return;
            }

            $webinar->refresh();
        }
    }

    public function backoff(): array
    {
        return [300, 300, 600, 600, 900, 900, 1800];
    }
}