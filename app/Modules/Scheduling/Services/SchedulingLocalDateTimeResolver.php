<?php

namespace App\Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class SchedulingLocalDateTimeResolver
{
    public function resolve(
        mixed $value,
        string $timezone,
        string $label,
    ): CarbonImmutable {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("A non-empty {$label} is required.");
        }

        $value = trim($value);

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException("Timezone [{$timezone}] is invalid.");
        }

        if (preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2})T(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)$/',
            $value,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException(
                "The {$label} must use YYYY-MM-DDTHH:MM local format.",
            );
        }

        $normalized = sprintf(
            '%s %s:%s:00',
            $matches['date'],
            $matches['hour'],
            $matches['minute'],
        );
        $candidates = $this->utcCandidatesForLocal($normalized, $timezone);

        if ($candidates === []) {
            throw new InvalidArgumentException(
                "The {$label} does not exist in timezone [{$timezone}] because of a clock transition.",
            );
        }

        if (count($candidates) > 1) {
            throw new InvalidArgumentException(
                "The {$label} is ambiguous in timezone [{$timezone}] because the local clock repeats that time.",
            );
        }

        return CarbonImmutable::createFromTimestampUTC($candidates[0]);
    }

    /**
     * @return array<int, int>
     */
    private function utcCandidatesForLocal(
        string $normalized,
        string $timezone,
    ): array {
        try {
            $naive = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $normalized,
                'UTC',
            );
        } catch (Throwable) {
            return [];
        }

        if (! $naive instanceof CarbonImmutable
            || $naive->format('Y-m-d H:i:s') !== $normalized
        ) {
            return [];
        }

        $zone = new DateTimeZone($timezone);
        $naiveTimestamp = $naive->getTimestamp();
        $transitions = $zone->getTransitions(
            $naiveTimestamp - 172800,
            $naiveTimestamp + 172800,
        );
        $offsets = [];

        if (is_array($transitions)) {
            foreach ($transitions as $transition) {
                $offsets[] = (int) ($transition['offset'] ?? 0);
            }
        }

        $offsets[] = $zone->getOffset(
            (new DateTimeImmutable('@'.$naiveTimestamp))->setTimezone($zone),
        );
        $offsets = array_values(array_unique($offsets));
        $candidates = [];

        foreach ($offsets as $offset) {
            $candidate = $naiveTimestamp - $offset;
            $local = CarbonImmutable::createFromTimestampUTC($candidate)
                ->setTimezone($timezone);

            if ($local->format('Y-m-d H:i:s') === $normalized) {
                $candidates[] = $candidate;
            }
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates, SORT_NUMERIC);

        return $candidates;
    }
}