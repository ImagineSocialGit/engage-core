<?php

namespace App\Actions\Webinars\PostEvent;

use App\Contracts\Webinars\WebinarProvider;
use App\Jobs\Webinars\PostEvent\RoutePostWebinarRegistrationJob;
use App\Models\Webinar;

class DispatchPostWebinarFollowUpsAction
{
    public function execute(WebinarProvider $provider, Webinar $webinar): bool
    {
        if (data_get($webinar->meta, 'normalized.post_event.follow_ups_dispatched_at')) {
            return true;
        }

        $webinar->registrations()
            ->pluck('id')
            ->each(function ($registrationId) {
                RoutePostWebinarRegistrationJob::dispatch($registrationId)
                    ->onQueue('notifications');
            });

        $webinar->forceFill([
            'meta' => array_replace_recursive($webinar->fresh()->meta ?? [], [
                'normalized' => [
                    'post_event' => [
                        'follow_ups_dispatched_at' => now()->toIso8601String(),
                    ],
                ],
            ]),
        ])->save();

        return true;
    }
}