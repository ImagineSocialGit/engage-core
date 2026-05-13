<?php

namespace App\Actions\Caching;

use App\Models\Webinar;
use App\Support\Caching\CacheKey;
use Illuminate\Support\Facades\Cache;

class FlushWebinarCachesAction
{
    public function handle(?Webinar $webinar = null, ?string $seriesSlug = null): void
    {
        $seriesSlug ??= $webinar?->series?->slug ?? 'default';

        Cache::forget(CacheKey::nextUpcomingWebinar());
        Cache::forget(CacheKey::nextUpcomingWebinar($seriesSlug));
        Cache::forget(CacheKey::activeWebinarSeries());
        Cache::forget(CacheKey::publicPageConfig('webinar-registration', $seriesSlug));
        Cache::forget(CacheKey::webinarLandingPage($seriesSlug));
    }
}