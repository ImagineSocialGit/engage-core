<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesVariant;
use App\Support\Caching\CacheKey;
use Illuminate\Support\Facades\Cache;

class GetNextUpcomingWebinarAction
{
    public function __construct(
        private readonly ResolveRegisterableWebinarAction $resolveRegisterableWebinar,
    ) {}

    public function getGlobal(): ?Webinar
    {
        $cacheKey = $this->globalCacheKey();
        $cached = $this->cachedWebinarId($cacheKey);

        if ($cached['found']) {
            if ($cached['webinar_id'] === null) {
                return null;
            }

            $webinar = $this->hydrateGlobal($cached['webinar_id']);

            if (
                $webinar
                && $this->resolveRegisterableWebinar->isRegisterable($webinar)
            ) {
                return $webinar;
            }

            Cache::forget($cacheKey);
        }

        $webinar = $this->resolveRegisterableWebinar->getGlobal();

        $this->cacheResolvedWebinar($cacheKey, $webinar);

        return $webinar;
    }

    public function getForSeries(WebinarSeries $series): ?Webinar
    {
        $cacheKey = $this->seriesCacheKey($series);
        $cached = $this->cachedWebinarId($cacheKey);

        if ($cached['found']) {
            if ($cached['webinar_id'] === null) {
                return null;
            }

            $webinar = $this->hydrateForSeries(
                $cached['webinar_id'],
                $series,
            );

            if (
                $webinar
                && $this->resolveRegisterableWebinar->isRegisterableForSeries(
                    $webinar,
                    $series,
                )
            ) {
                return $webinar;
            }

            Cache::forget($cacheKey);
        }

        $webinar = $this->resolveRegisterableWebinar->getForSeries($series);

        $this->cacheResolvedWebinar($cacheKey, $webinar);

        return $webinar;
    }


    public function getForVariant(WebinarSeriesVariant $variant): ?Webinar
    {
        if (! $variant->exists) {
            return $this->getForSeries($variant->webinarSeries);
        }

        $cacheKey = $this->variantCacheKey($variant);
        $cached = $this->cachedWebinarId($cacheKey);

        if ($cached['found']) {
            if ($cached['webinar_id'] === null) {
                return null;
            }

            $webinar = Webinar::query()
                ->with(['webinarSeries', 'webinarSeriesVariant'])
                ->whereKey($cached['webinar_id'])
                ->where('webinar_series_variant_id', $variant->getKey())
                ->first();

            if ($webinar && $this->resolveRegisterableWebinar->isRegisterable($webinar)) {
                return $webinar;
            }

            Cache::forget($cacheKey);
        }

        $webinar = $this->resolveRegisterableWebinar->getForVariant($variant);
        $this->cacheResolvedWebinar($cacheKey, $webinar);

        return $webinar;
    }

    public function forgetForVariant(WebinarSeriesVariant $variant): void
    {
        if ($variant->exists) {
            Cache::forget($this->variantCacheKey($variant));
        }

        if ($variant->webinarSeries) {
            $this->forgetForSeries($variant->webinarSeries);
        }
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

        if ($webinar->webinarSeriesVariant) {
            $this->forgetForVariant($webinar->webinarSeriesVariant);
        } elseif ($webinar->webinarSeries) {
            $this->forgetForSeries($webinar->webinarSeries);
        }
    }

    /**
     * @return array{found: bool, webinar_id: int|null}
     */
    private function cachedWebinarId(string $cacheKey): array
    {
        $cached = Cache::get($cacheKey);

        if (
            is_array($cached)
            && array_key_exists('webinar_id', $cached)
        ) {
            if ($cached['webinar_id'] === null) {
                return [
                    'found' => true,
                    'webinar_id' => null,
                ];
            }

            if (is_numeric($cached['webinar_id'])) {
                return [
                    'found' => true,
                    'webinar_id' => (int) $cached['webinar_id'],
                ];
            }

            return [
                'found' => false,
                'webinar_id' => null,
            ];
        }

        // Read legacy integer cache values safely during deployment.
        if (is_numeric($cached)) {
            return [
                'found' => true,
                'webinar_id' => (int) $cached,
            ];
        }

        return [
            'found' => false,
            'webinar_id' => null,
        ];
    }

    private function cacheResolvedWebinar(
        string $cacheKey,
        ?Webinar $webinar,
    ): void {
        Cache::put(
            $cacheKey,
            ['webinar_id' => $webinar?->getKey()],
            $this->ttlForWebinar($webinar),
        );
    }

    private function hydrateGlobal(int $webinarId): ?Webinar
    {
        return Webinar::query()
            ->with(['webinarSeries', 'webinarSeriesVariant'])
            ->whereKey($webinarId)
            ->first();
    }

    private function hydrateForSeries(
        int $webinarId,
        WebinarSeries $series,
    ): ?Webinar {
        return Webinar::query()
            ->whereKey($webinarId)
            ->where('webinar_series_id', $series->getKey())
            ->first();
    }

    private function ttlForWebinar(?Webinar $webinar): int
    {
        if (! $webinar?->starts_at) {
            return max(
                1,
                (int) config(
                    'cache-keys.ttl.next_upcoming_webinar_empty_seconds',
                    300,
                ),
            );
        }

        $configuredRefreshSeconds = max(
            1,
            (int) config(
                'cache-keys.ttl.next_upcoming_webinar_min_seconds',
                60,
            ),
        );

        $remainingWindowSeconds = (int) ceil(
            now()->diffInSeconds(
                $webinar->starts_at->copy()->addMinutes(
                    ResolveRegisterableWebinarAction::LATE_JOIN_MINUTES,
                ),
                false,
            ),
        );

        return max(
            1,
            min($configuredRefreshSeconds, $remainingWindowSeconds),
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

    private function variantCacheKey(WebinarSeriesVariant $variant): string
    {
        return CacheKey::nextUpcomingWebinar($variant->publicSlug());
    }
}