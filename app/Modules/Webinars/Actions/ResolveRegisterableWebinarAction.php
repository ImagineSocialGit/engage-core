<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ResolveRegisterableWebinarAction
{
    public const LATE_JOIN_MINUTES = 10;

    public function getGlobal(): ?Webinar
    {
        return $this->registerableQuery()
            ->whereHas(
                'webinarSeries',
                fn (Builder $query): Builder => $query->where('status', 'active'),
            )
            ->orderBy('starts_at')
            ->first();
    }

    public function getForSeries(WebinarSeries $series): ?Webinar
    {
        if ($series->status !== 'active') {
            return null;
        }

        return $this->registerableQuery()
            ->where('webinar_series_id', $series->getKey())
            ->orderBy('starts_at')
            ->first();
    }

    public function findForSeries(WebinarSeries $series, int $webinarId): ?Webinar
    {
        if ($series->status !== 'active') {
            return null;
        }

        return $this->registerableQuery()
            ->where('webinar_series_id', $series->getKey())
            ->whereKey($webinarId)
            ->first();
    }

    public function isRegisterable(Webinar $webinar): bool
    {
        return $webinar->webinar_series_id !== null
            && $webinar->starts_at !== null
            && $webinar->starts_at->greaterThanOrEqualTo($this->lateJoinCutoff())
            && $webinar->webinarSeries?->status === 'active';
    }

    public function isRegisterableForSeries(
        Webinar $webinar,
        WebinarSeries $series,
    ): bool {
        return $series->status === 'active'
            && $webinar->webinar_series_id === $series->getKey()
            && $webinar->starts_at !== null
            && $webinar->starts_at->greaterThanOrEqualTo($this->lateJoinCutoff());
    }

    /**
     * @return Builder<Webinar>
     */
    private function registerableQuery(): Builder
    {
        return Webinar::query()
            ->where('starts_at', '>=', $this->lateJoinCutoff());
    }

    private function lateJoinCutoff(): Carbon
    {
        return now()->subMinutes(self::LATE_JOIN_MINUTES);
    }
}
