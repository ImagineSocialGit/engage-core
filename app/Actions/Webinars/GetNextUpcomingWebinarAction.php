<?php

namespace App\Actions\Webinars;

use App\Models\Webinar;
use App\Models\WebinarSeries;
use Illuminate\Support\Facades\Cache;

class GetNextUpcomingWebinarAction
{
    private const LATE_JOIN_MINUTES = 10;

    public function getGlobal(): ?Webinar
    {
        return Cache::remember(
            $this->globalCacheKey(),
            $this->globalTtl(),
            fn () => $this->queryGlobal()
        );
    }

    public function getForSeries(WebinarSeries $series): ?Webinar
    {
        return Cache::remember(
            $this->seriesCacheKey($series),
            $this->seriesTtl($series),
            fn () => $this->queryForSeries($series)
        );
    }

    public function forgetGlobal(): void
    {
        Cache::forget($this->globalCacheKey());
    }

    public function forgetForSeries(WebinarSeries $series): void
    {
        Cache::forget($this->seriesCacheKey($series));
    }

    public function forgetForWebinar(Webinar $webinar): void
    {
        $this->forgetGlobal();

        if ($webinar->series) {
            $this->forgetForSeries($webinar->series);
        }
    }

    private function queryGlobal(): ?Webinar
    {
        return Webinar::query()
            ->with('series')
            ->where('status', 'active')
            ->whereNotNull('series_id')
            ->where('starts_at', '>', now()->subMinutes(self::LATE_JOIN_MINUTES))
            ->orderBy('starts_at')
            ->first();
    }

    private function queryForSeries(WebinarSeries $series): ?Webinar
    {
        return Webinar::query()
            ->where('series_id', $series->id)
            ->where('status', 'active')
            ->where('starts_at', '>', now()->subMinutes(self::LATE_JOIN_MINUTES))
            ->orderBy('starts_at')
            ->first();
    }

    private function globalTtl(): int
    {
        return $this->ttlForWebinar($this->queryGlobal());
    }

    private function seriesTtl(WebinarSeries $series): int
    {
        return $this->ttlForWebinar($this->queryForSeries($series));
    }

    private function ttlForWebinar(?Webinar $webinar): int
    {
        if (! $webinar?->starts_at) {
            return 300;
        }

        return max(
            60,
            now()->diffInSeconds(
                $webinar->starts_at->copy()->addMinutes(self::LATE_JOIN_MINUTES),
                false
            )
        );
    }

    private function globalCacheKey(): string
    {
        return 'webinars:next-upcoming:global';
    }

    private function seriesCacheKey(WebinarSeries $series): string
    {
        return "webinars:next-upcoming:series:{$series->id}";
    }
}