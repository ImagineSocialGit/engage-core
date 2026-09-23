<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesVariant;
use App\Modules\Webinars\Services\WebinarTimezoneResolver;
use Illuminate\Support\Facades\Schema;

final class ResolvePublicWebinarSeriesVariantAction
{
    public function findByPublicSlug(string $publicSlug): ?WebinarSeriesVariant
    {
        $publicSlug = trim($publicSlug);

        if ($publicSlug === '') {
            return null;
        }

        if (Schema::hasTable('webinar_series_variants')) {
            $variant = WebinarSeriesVariant::query()
                ->with('webinarSeries')
                ->where('public_slug', $publicSlug)
                ->where('status', 'active')
                ->whereHas(
                    'webinarSeries',
                    fn ($query) => $query->where('status', 'active'),
                )
                ->first();

            if ($variant instanceof WebinarSeriesVariant) {
                return $variant;
            }
        }

        $series = WebinarSeries::query()
            ->where('slug', $publicSlug)
            ->where('status', 'active')
            ->first();

        if (! $series instanceof WebinarSeries) {
            return null;
        }

        if (Schema::hasTable('webinar_series_variants')) {
            $variant = $series->variants()
                ->where('status', 'active')
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if ($variant instanceof WebinarSeriesVariant) {
                return $variant;
            }
        }

        $legacy = new WebinarSeriesVariant([
            'webinar_series_id' => $series->getKey(),
            'key' => 'legacy',
            'name' => 'Primary',
            'public_slug' => $series->slug,
            'timezone' => app(WebinarTimezoneResolver::class)->resolve(
                data_get($series->meta, 'display_timezone'),
            ),
            'platform' => $series->providerKey(),
            'provider_event_type' => $series->providerEventTypeKey(),
            'provider_match_title' => $series->title,
            'status' => 'active',
            'is_default' => true,
            'meta' => ['compatibility' => ['legacy_series_fallback' => true]],
        ]);
        $legacy->setRelation('webinarSeries', $series);

        return $legacy;
    }
}