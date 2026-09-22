<?php

namespace App\Modules\Reporting\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class ScheduledReportScheduleCalculator
{
    /**
     * @param array<int, int|string> $daysOfWeek ISO-8601 weekdays, Monday=1.
     */
    public function next(
        array $daysOfWeek,
        string $sendTime,
        string $timezone,
        ?CarbonInterface $after = null,
    ): CarbonImmutable {
        $days = collect($daysOfWeek)
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($days === []) {
            throw new InvalidArgumentException(
                'Scheduled reports require at least one day of the week.',
            );
        }

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $sendTime) !== 1) {
            throw new InvalidArgumentException(
                'Scheduled report send time must use HH:MM.',
            );
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException(
                "Scheduled report timezone [{$timezone}] is invalid.",
            );
        }

        [$hour, $minute] = array_map('intval', explode(':', $sendTime));
        $cursor = $after instanceof CarbonInterface
            ? CarbonImmutable::instance($after)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);

        for ($offset = 0; $offset <= 8; $offset++) {
            $candidate = $cursor
                ->startOfDay()
                ->addDays($offset)
                ->setTime($hour, $minute);

            if (! in_array($candidate->dayOfWeekIso, $days, true)) {
                continue;
            }

            if ($candidate->lessThanOrEqualTo($cursor)) {
                continue;
            }

            return $candidate->utc();
        }

        throw new InvalidArgumentException(
            'Unable to calculate the next scheduled report delivery.',
        );
    }
}