<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarSeries;

class WebinarScheduleProfileResolver
{
    public function resolveForWebinar(?Webinar $webinar): ?WebinarScheduleProfile
    {
        $webinar?->loadMissing(['webinarScheduleProfile.items', 'webinarSeries.webinarScheduleProfile.items']);

        if ($webinar?->webinarScheduleProfile?->is_active && $webinar->webinarScheduleProfile->status === WebinarScheduleProfile::STATUS_ACTIVE) {
            return $webinar->webinarScheduleProfile;
        }

        if ($webinar?->webinarSeries instanceof WebinarSeries) {
            return $this->resolveForSeries($webinar->webinarSeries);
        }

        return $this->defaultProfile();
    }

    public function resolveForSeries(?WebinarSeries $series): ?WebinarScheduleProfile
    {
        $series?->loadMissing('webinarScheduleProfile.items');

        if (
            $series?->webinarScheduleProfile?->is_active
            && $series->webinarScheduleProfile->status === WebinarScheduleProfile::STATUS_ACTIVE
        ) {
            return $series->webinarScheduleProfile;
        }

        return $this->defaultProfile();
    }

    private function defaultProfile(): ?WebinarScheduleProfile
    {
        return WebinarScheduleProfile::query()
            ->active()
            ->where('is_default', true)
            ->with('items')
            ->orderBy('id')
            ->first();
    }
}