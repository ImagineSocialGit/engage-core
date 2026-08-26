<?php

namespace App\Modules\Core\Services\BusinessCalendar;

use App\Modules\Core\Models\BusinessCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

class BusinessCalendarDateCalculator
{
    public function __construct(
        private readonly DefaultBusinessCalendarResolver $calendars,
    ) {}

    public function addBusinessDays(
        CarbonInterface $from,
        int $businessDays,
        ?BusinessCalendar $calendar = null,
        ?string $timezone = null,
    ): CarbonImmutable {
        if ($businessDays < 0) {
            throw new DomainException('Business days cannot be negative.');
        }

        $calendar ??= $this->calendars->resolve();
        $calendar->loadMissing('exclusions');
        $skippedWeekdays = $calendar->skippedWeekdays();

        if (count($skippedWeekdays) === 7) {
            throw new DomainException('The business calendar must count at least one weekday.');
        }

        $timezone = $timezone ?: (string) config(
            'client.timezone',
            config('app.timezone', 'UTC'),
        );
        $cursor = CarbonImmutable::instance($from)->setTimezone($timezone);
        $remaining = $businessDays;
        $iterations = 0;
        $maximumIterations = max(3660, (($businessDays + 1) * 14) + 3660);

        while ($remaining > 0) {
            $iterations++;

            if ($iterations > $maximumIterations) {
                throw new DomainException('The business calendar does not contain enough counted dates.');
            }

            $cursor = $cursor->addDay();

            if (in_array($cursor->isoWeekday(), $skippedWeekdays, true)) {
                continue;
            }

            if ($calendar->exclusions->contains(
                fn ($exclusion): bool => $exclusion->matches($cursor),
            )) {
                continue;
            }

            $remaining--;
        }

        return $cursor->utc();
    }
}