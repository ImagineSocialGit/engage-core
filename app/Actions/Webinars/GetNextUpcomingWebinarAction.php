<?php

namespace App\Actions\Webinars;

use App\Models\Webinar;
use App\Models\WebinarSeries;
use App\Support\Caching\CacheKey;
use Illuminate\Support\Facades\Cache;

class GetNextUpcomingWebinarAction
{
    private const LATE_JOIN_MINUTES = 10;

    public function getGlobal(): ?Webinar
    {
        $webinarId = Cache::remember(
            $this->globalCacheKey(),
            $this->globalTtl(),
            fn (): ?int => $this->queryGlobal()?->id
        );

        return $this->hydrateGlobal($webinarId);
    }

    public function getForSeries(WebinarSeries $series): ?Webinar
    {
        $webinarId = Cache::remember(
            $this->seriesCacheKey($series),
            $this->seriesTtl($series),
            fn (): ?int => $this->queryForSeries($series)?->id
        );

        return $this->hydrateForSeries($webinarId, $series);
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

        if ($webinar->webinarSeries) {
            $this->forgetForSeries($webinar->webinarSeries);
        }
    }

    private function queryGlobal(): ?Webinar
    {
        return Webinar::query()
            ->whereNotNull('webinar_series_id')
            ->where('starts_at', '>', now()->subMinutes(self::LATE_JOIN_MINUTES))
            ->orderBy('starts_at')
            ->first();
    }

    private function queryForSeries(WebinarSeries $series): ?Webinar
    {
        return Webinar::query()
            ->where('webinar_series_id', $series->id)
            ->where('starts_at', '>', now()->subMinutes(self::LATE_JOIN_MINUTES))
            ->orderBy('starts_at')
            ->first();
    }

    private function hydrateGlobal(?int $webinarId): ?Webinar
    {
        if (! $webinarId) {
            return null;
        }

        return Webinar::query()
            ->with('series')
            ->whereKey($webinarId)
            ->first();
    }

    private function hydrateForSeries(?int $webinarId, WebinarSeries $series): ?Webinar
    {
        if (! $webinarId) {
            return null;
        }

        return Webinar::query()
            ->whereKey($webinarId)
            ->where('webinar_series_id', $series->id)
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
            return (int) config('cache-keys.ttl.next_upcoming_webinar_empty_seconds');
        }

        return max(
            (int) config('cache-keys.ttl.next_upcoming_webinar_min_seconds'),
            now()->diffInSeconds(
                $webinar->starts_at->copy()->addMinutes(self::LATE_JOIN_MINUTES),
                false
            )
        );
    }

    private function globalCacheKey(): string
    {
        return CacheKey::nextUpcomingWebinar();
    }

    private function seriesCacheKey(WebinarSeries $series): string
    {
        return CacheKey::nextUpcomingWebinar($series->slug);
    }
}