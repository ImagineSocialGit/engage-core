<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Carbon\CarbonInterface;

final class WebinarProviderSchedulePolicy
{
    public const PROVIDER_LIST_SCHEDULE_SOURCE = 'zoom_list_api';

    public function allowsProviderOccurrence(
        WebinarSeries $series,
        ProviderWebinarData $webinar,
    ): bool {
        if (! $this->providerDataCarriesScheduleEvidence($webinar->meta)) {
            return true;
        }

        return $this->allows(
            provider: $series->providerKey(),
            providerEventType: $series->providerEventTypeKey(),
            startsAt: $webinar->startsAt,
        );
    }

    public function allowsStoredOccurrence(Webinar $webinar): bool
    {
        $providerData = data_get($webinar->meta, 'provider.data');

        if (! is_array($providerData)
            || ! $this->providerDataCarriesScheduleEvidence($providerData)
        ) {
            return true;
        }

        return ! $this->isConfiguredScheduleOutlier($webinar);
    }

    public function isConfiguredScheduleOutlier(Webinar $webinar): bool
    {
        $increment = $this->incrementMinutes(
            $webinar->providerKey(),
            $webinar->providerEventTypeKey(),
        );

        if ($increment === null || $webinar->starts_at === null) {
            return false;
        }

        return ! $this->startsOnGrid($webinar->starts_at, $increment);
    }

    public function incrementMinutes(
        string $provider,
        string $providerEventType,
    ): ?int {
        $configured = config(sprintf(
            'webinars.providers.%s.event_types.%s.schedule_increment_minutes',
            $provider,
            $providerEventType,
        ));

        if (! is_numeric($configured)) {
            return null;
        }

        $minutes = (int) $configured;

        return $minutes > 0 && $minutes <= 60
            ? $minutes
            : null;
    }

    /** @param array<string, mixed> $providerData */
    private function providerDataCarriesScheduleEvidence(array $providerData): bool
    {
        return ($providerData['schedule_source'] ?? null)
            === self::PROVIDER_LIST_SCHEDULE_SOURCE;
    }

    private function allows(
        string $provider,
        string $providerEventType,
        ?CarbonInterface $startsAt,
    ): bool {
        $increment = $this->incrementMinutes($provider, $providerEventType);

        if ($increment === null || $startsAt === null) {
            return true;
        }

        return $this->startsOnGrid($startsAt, $increment);
    }

    private function startsOnGrid(CarbonInterface $startsAt, int $increment): bool
    {
        return (int) $startsAt->second === 0
            && ((int) $startsAt->minute % $increment) === 0;
    }
}