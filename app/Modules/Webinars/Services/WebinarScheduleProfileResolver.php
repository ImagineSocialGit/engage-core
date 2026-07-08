<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;

class WebinarScheduleProfileResolver
{
    public function resolveForWebinar(?Webinar $webinar): ?WebinarScheduleProfile
    {
        $webinar?->loadMissing(['webinarScheduleProfile.items', 'webinarSeries.webinarScheduleProfile.items']);

        if ($webinar?->webinarScheduleProfile?->is_active && $webinar->webinarScheduleProfile->status === WebinarScheduleProfile::STATUS_ACTIVE) {
            return $webinar->webinarScheduleProfile;
        }

        if ($webinar?->webinarSeries?->webinarScheduleProfile?->is_active && $webinar->webinarSeries->webinarScheduleProfile->status === WebinarScheduleProfile::STATUS_ACTIVE) {
            return $webinar->webinarSeries->webinarScheduleProfile;
        }

        return WebinarScheduleProfile::query()
            ->active()
            ->where('is_default', true)
            ->with('items')
            ->orderBy('id')
            ->first();
    }
}
