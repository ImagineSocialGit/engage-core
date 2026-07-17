<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Eloquent\Builder;

class ResolveRegisterableWebinarAction
{
    public const LATE_JOIN_MINUTES = 10;

    public function getGlobal(): ?Webinar
    {
        return $this->registerableQuery()
            ->whereNotNull('webinar_series_id')
            ->orderBy('starts_at')
            ->first();
    }

    public function getForSeries(WebinarSeries $series): ?Webinar
    {
        return $this->registerableQuery()
            ->where('webinar_series_id', $series->getKey())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @return Builder<Webinar>
     */
    private function registerableQuery(): Builder
    {
        return Webinar::query()
            ->where('starts_at', '>', now()->subMinutes(self::LATE_JOIN_MINUTES));
    }
}
