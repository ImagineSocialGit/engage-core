<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use Illuminate\Support\Collection;

class WebinarMessageChainBindingResolver
{
    public function __construct(
        private readonly WebinarScheduleProfileResolver $scheduleProfileResolver,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
    ) {}

    public function resolveForWebinar(
        Webinar $webinar,
        string $messageAreaKey,
    ): WebinarSeriesMessageChainBinding|WebinarScheduleProfileChainBinding|null {
        $webinar->loadMissing('webinarSeries');

        if ($webinar->webinarSeries instanceof WebinarSeries) {
            $seriesBinding = $this->seriesBinding(
                series: $webinar->webinarSeries,
                messageAreaKey: $messageAreaKey,
            );

            if ($seriesBinding instanceof WebinarSeriesMessageChainBinding) {
                return $seriesBinding;
            }
        }

        $profile = $this->scheduleProfileResolver->resolveForWebinar($webinar);

        if ($profile === null) {
            return null;
        }

        return WebinarScheduleProfileChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants')
            ->where('webinar_schedule_profile_id', $profile->getKey())
            ->where('message_area_key', $messageAreaKey)
            ->active()
            ->first();
    }

    public function resolveForSeries(
        WebinarSeries $series,
        string $messageAreaKey,
    ): WebinarSeriesMessageChainBinding|WebinarScheduleProfileChainBinding|null {
        $seriesBinding = $this->seriesBinding(
            series: $series,
            messageAreaKey: $messageAreaKey,
        );

        if ($seriesBinding instanceof WebinarSeriesMessageChainBinding) {
            return $seriesBinding;
        }

        $profile = $this->scheduleProfileResolver->resolveForSeries($series);

        if ($profile === null) {
            return null;
        }

        return WebinarScheduleProfileChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants')
            ->where('webinar_schedule_profile_id', $profile->getKey())
            ->where('message_area_key', $messageAreaKey)
            ->active()
            ->first();
    }

    /**
     * @return Collection<int, WebinarSeriesMessageChainBinding|WebinarScheduleProfileChainBinding>
     */
    public function effectiveBindingsForSeries(WebinarSeries $series): Collection
    {
        return $this->messageAreaRegistry
            ->enabled()
            ->filter(fn ($area): bool => $area->isTemplate())
            ->map(fn ($area) => $this->resolveForSeries(
                series: $series,
                messageAreaKey: $area->key,
            ))
            ->filter()
            ->unique(fn ($binding): string => implode(':', [
                $binding::class,
                $binding->getKey(),
            ]))
            ->values();
    }

    private function seriesBinding(
        WebinarSeries $series,
        string $messageAreaKey,
    ): ?WebinarSeriesMessageChainBinding {
        return WebinarSeriesMessageChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants')
            ->where('webinar_series_id', $series->getKey())
            ->where('message_area_key', $messageAreaKey)
            ->active()
            ->first();
    }
}