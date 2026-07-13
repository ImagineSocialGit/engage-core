<?php

namespace App\Modules\Messaging\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class MessageSendTimeResolver
{
    /**
     * @param array<string, mixed> $definition
     */
    public function resolve(
        array $definition,
        Carbon $triggeredAt,
        ?Carbon $anchor,
    ): Carbon {
        if (($definition['timing'] ?? null) === 'immediate') {
            return $triggeredAt->copy();
        }

        $schedule = $definition['schedule'] ?? null;

        if (! is_array($schedule)) {
            throw new InvalidArgumentException('Scheduled message definition is missing [schedule].');
        }

        return match ($schedule['type'] ?? null) {
            'delay' => $this->resolveDelay($schedule, $triggeredAt),
            'anchored' => $this->resolveAnchored($schedule, $anchor),
            'next_day_at' => $this->resolveNextDayAt(
                schedule: $schedule,
                base: $anchor ?? $triggeredAt,
            ),
            default => throw new InvalidArgumentException('Scheduled message definition has invalid [schedule.type].'),
        };
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function resolveDelay(array $schedule, Carbon $triggeredAt): Carbon
    {
        return $triggeredAt->copy()->addMinutes($this->requiredMinutes($schedule));
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function resolveAnchored(array $schedule, ?Carbon $anchor): Carbon
    {
        if (! $anchor instanceof Carbon) {
            throw new InvalidArgumentException('Scheduled message definition requires an anchor.');
        }

        return $anchor->copy()->addMinutes($this->requiredMinutes($schedule));
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function resolveNextDayAt(array $schedule, Carbon $base): Carbon
    {
        $time = $schedule['time'] ?? null;

        if (! is_string($time) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidArgumentException('Scheduled message definition has invalid [schedule.time]. Expected [HH:MM].');
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        $timezone = config('client.timezone', config('app.timezone', 'UTC'));

        if (! is_string($timezone) || trim($timezone) === '') {
            $timezone = 'UTC';
        }

        return $base->copy()
            ->setTimezone($timezone)
            ->addDay()
            ->setTime($hour, $minute, 0);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function requiredMinutes(array $schedule): int
    {
        $minutes = $schedule['minutes'] ?? null;

        if (! is_int($minutes)) {
            throw new InvalidArgumentException('Scheduled message definition has invalid [schedule.minutes].');
        }

        return $minutes;
    }
}
