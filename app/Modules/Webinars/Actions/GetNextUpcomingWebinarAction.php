<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\Caching\CacheKey;
use Illuminate\Support\Facades\Cache;

class GetNextUpcomingWebinarAction
{
    public function __construct(
        private readonly ResolveRegisterableWebinarAction $resolveRegisterableWebinar,
    ) {}

    public function getGlobal(): ?Webinar
    {
        $webinarId = Cache::remember(
            $this->globalCacheKey(),
            $this->globalTtl(),
            fn (): ?int => $this->resolveRegisterableWebinar->getGlobal()?->getKey(),
        );

        return $this->hydrateGlobal($webinarId);
    }

    public function getForSeries(WebinarSeries $series): ?Webinar
    {
        $webinarId = Cache::remember(
            $this->seriesCacheKey($series),
            $this->seriesTtl($series),
            fn (): ?int => $this->resolveRegisterableWebinar->getForSeries($series)?->getKey(),
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
            ->where('webinar_series_id', $series->getKey())
            ->first();
    }

    private function globalTtl(): int
    {
        return $this->ttlForWebinar($this->resolveRegisterableWebinar->getGlobal());
    }

    private function seriesTtl(WebinarSeries $series): int
    {
        return $this->ttlForWebinar($this->resolveRegisterableWebinar->getForSeries($series));
    }

    private function ttlForWebinar(?Webinar $webinar): int
    {
        if (! $webinar?->starts_at) {
            return (int) config('cache-keys.ttl.next_upcoming_webinar_empty_seconds');
        }

        return max(
            (int) config('cache-keys.ttl.next_upcoming_webinar_min_seconds'),
            now()->diffInSeconds(
                $webinar->starts_at->copy()->addMinutes(
                    ResolveRegisterableWebinarAction::LATE_JOIN_MINUTES,
                ),
                false,
            ),
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
