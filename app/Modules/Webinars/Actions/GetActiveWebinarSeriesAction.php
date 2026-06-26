<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\Caching\CacheKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class GetActiveWebinarSeriesAction
{
    public function handle(): Collection
    {
        $seriesIds = Cache::remember(
            CacheKey::activeWebinarSeries(),
            (int) config('cache-keys.ttl.active_webinar_series_min_seconds', 300),
            fn (): array => WebinarSeries::query()
                ->where('status', 'active')
                ->orderBy('title')
                ->pluck('id')
                ->all()
        );

        return WebinarSeries::query()
            ->whereKey($seriesIds)
            ->orderBy('title')
            ->get();
    }

    public function findBySlug(string $seriesSlug): ?WebinarSeries
    {
        return $this->handle()
            ->firstWhere('slug', $seriesSlug);
    }
}