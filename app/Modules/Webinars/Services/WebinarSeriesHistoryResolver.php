<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Support\Collection;

final class WebinarSeriesHistoryResolver
{
    public function __construct(
        private readonly WebinarProviderSchedulePolicy $schedulePolicy,
    ) {}

    /**
     * @param Collection<int, Webinar> $occurrences
     * @return Collection<int, Webinar>
     */
    public function resolve(WebinarSeries $series, Collection $occurrences): Collection
    {
        return $occurrences
            ->filter(fn (Webinar $webinar): bool =>
                ! $webinar->isHidden()
                && ($webinar->ends_at?->isPast() ?? false)
                && $this->schedulePolicy->allowsStoredOccurrence($webinar)
            )
            ->groupBy(fn (Webinar $webinar): string => $this->slotKey($webinar))
            ->map(fn (Collection $slot): ?Webinar => $this->canonical($series, $slot))
            ->filter(fn (mixed $webinar): bool => $webinar instanceof Webinar)
            ->sortByDesc(fn (Webinar $webinar): string =>
                $webinar->ends_at?->format('Y-m-d H:i:s') ?? ''
            )
            ->values();
    }

    private function slotKey(Webinar $webinar): string
    {
        if ($webinar->starts_at === null) {
            return 'webinar:'.(string) $webinar->getKey();
        }

        return 'starts_at:'.$webinar->starts_at
            ->copy()
            ->utc()
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param Collection<int, Webinar> $slot
     */
    private function canonical(WebinarSeries $series, Collection $slot): ?Webinar
    {
        $ids = $slot
            ->map(fn (Webinar $webinar): int => (int) $webinar->getKey())
            ->all();

        return $slot
            ->sort(function (Webinar $left, Webinar $right) use ($series, $ids): int {
                foreach ([
                    $this->isExplicitReplacement($right, $ids) <=> $this->isExplicitReplacement($left, $ids),
                    $this->registrationCount($right) <=> $this->registrationCount($left),
                    ((int) $right->matchesSeriesProviderIdentity($series))
                        <=> ((int) $left->matchesSeriesProviderIdentity($series)),
                    ((int) $right->getKey()) <=> ((int) $left->getKey()),
                ] as $comparison) {
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return 0;
            })
            ->first();
    }

    /** @param array<int, int> $slotIds */
    private function isExplicitReplacement(Webinar $webinar, array $slotIds): int
    {
        $sourceId = $webinar->replacement_of_webinar_id;

        return $sourceId !== null
            && in_array((int) $sourceId, $slotIds, true)
                ? 1
                : 0;
    }

    private function registrationCount(Webinar $webinar): int
    {
        $count = $webinar->getAttribute('registrations_count');

        return is_numeric($count)
            ? (int) $count
            : $webinar->registrations()->count();
    }
}