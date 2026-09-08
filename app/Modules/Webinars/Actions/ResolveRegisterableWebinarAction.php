<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarProviderSchedulePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ResolveRegisterableWebinarAction
{
    public const LATE_JOIN_MINUTES = 10;

    public function __construct(
        private readonly WebinarProviderSchedulePolicy $providerSchedulePolicy,
    ) {}

    public function getGlobal(): ?Webinar
    {
        return $this->firstScheduleEligible(
            $this->registerableQuery()
                ->matchingCurrentSeriesProvider()
                ->whereHas(
                    'webinarSeries',
                    fn (Builder $query): Builder => $query->where('status', 'active'),
                )
                ->with('webinarSeries')
                ->orderBy('starts_at'),
        );
    }

    public function getForSeries(WebinarSeries $series): ?Webinar
    {
        if ($series->status !== 'active') {
            return null;
        }

        return $this->firstScheduleEligible(
            $this->registerableQuery()
                ->forSeriesProviderIdentity($series)
                ->orderBy('starts_at'),
        );
    }

    public function findForSeries(WebinarSeries $series, int $webinarId): ?Webinar
    {
        if ($series->status !== 'active') {
            return null;
        }

        $webinar = $this->registerableQuery()
            ->forSeriesProviderIdentity($series)
            ->whereKey($webinarId)
            ->first();

        return $webinar instanceof Webinar
            && $this->providerSchedulePolicy->allowsStoredOccurrence($webinar)
                ? $webinar
                : null;
    }

    public function getFutureForSeries(WebinarSeries $series): ?Webinar
    {
        if ($series->status !== 'active') {
            return null;
        }

        return $this->firstScheduleEligible(
            Webinar::query()
                ->forSeriesProviderIdentity($series)
                ->providerActive()
                ->visible()
                ->where('starts_at', '>', now())
                ->orderBy('starts_at'),
        );
    }

    public function isRegisterable(Webinar $webinar): bool
    {
        return $webinar->webinar_series_id !== null
            && $webinar->isProviderActive()
            && ! $webinar->isHidden()
            && $webinar->starts_at !== null
            && $this->providerSchedulePolicy->allowsStoredOccurrence($webinar)
            && $webinar->starts_at->greaterThanOrEqualTo($this->lateJoinCutoff())
            && $webinar->webinarSeries?->status === 'active'
            && $webinar->matchesSeriesProviderIdentity();
    }

    public function isRegisterableForSeries(
        Webinar $webinar,
        WebinarSeries $series,
    ): bool {
        return $series->status === 'active'
            && $webinar->isProviderActive()
            && ! $webinar->isHidden()
            && $webinar->webinar_series_id === $series->getKey()
            && $webinar->matchesSeriesProviderIdentity($series)
            && $webinar->starts_at !== null
            && $this->providerSchedulePolicy->allowsStoredOccurrence($webinar)
            && $webinar->starts_at->greaterThanOrEqualTo($this->lateJoinCutoff());
    }

    /**
     * @return Builder<Webinar>
     */
    private function registerableQuery(): Builder
    {
        return Webinar::query()
            ->providerActive()
            ->visible()
            ->where('starts_at', '>=', $this->lateJoinCutoff());
    }

    /** @param Builder<Webinar> $query */
    private function firstScheduleEligible(Builder $query): ?Webinar
    {
        return $query
            ->get()
            ->first(fn (Webinar $webinar): bool =>
                $this->providerSchedulePolicy->allowsStoredOccurrence($webinar)
            );
    }

    private function lateJoinCutoff(): Carbon
    {
        return now()->subMinutes(self::LATE_JOIN_MINUTES);
    }
}