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

        $type = $schedule['type'] ?? null;
        $minutes = $schedule['minutes'] ?? null;

        if (! is_int($minutes)) {
            throw new InvalidArgumentException('Scheduled message definition has invalid [schedule.minutes].');
        }

        return match ($type) {
            'delay' => $triggeredAt->copy()->addMinutes($minutes),

            'anchored' => $anchor instanceof Carbon
                ? $anchor->copy()->addMinutes($minutes)
                : throw new InvalidArgumentException('Scheduled message definition requires an anchor.'),

            default => throw new InvalidArgumentException('Scheduled message definition has invalid [schedule.type].'),
        };
    }
}