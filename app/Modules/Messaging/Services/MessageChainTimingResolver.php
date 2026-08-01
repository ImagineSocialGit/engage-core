<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageChainStep;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class MessageChainTimingResolver
{
    /**
     * @param array<string, mixed> $context
     */
    public function resolve(
        MessageChainStep $step,
        array $context,
        Carbon $baseAt,
    ): Carbon {
        $baseAt = $baseAt->copy()->utc();

        return match ($step->timing_type) {
            MessageChainStep::TIMING_IMMEDIATE => $baseAt,
            MessageChainStep::TIMING_DELAY => $baseAt
                ->copy()
                ->addSeconds((int) $step->offset_seconds),
            MessageChainStep::TIMING_ANCHORED => $this->anchor(
                step: $step,
                context: $context,
            )->addSeconds((int) $step->offset_seconds)->utc(),
            MessageChainStep::TIMING_NEXT_DAY_AT => $this->nextDayAt(
                step: $step,
                context: $context,
            ),
            default => throw new InvalidArgumentException(
                "MessageChainStep [{$step->getKey()}] has unsupported timing type [{$step->timing_type}].",
            ),
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function anchor(
        MessageChainStep $step,
        array $context,
    ): Carbon {
        $anchorKey = $step->anchor_key;

        if (! is_string($anchorKey) || trim($anchorKey) === '') {
            throw new InvalidArgumentException(
                "MessageChainStep [{$step->getKey()}] requires an anchor key.",
            );
        }

        $value = Arr::get($context, $anchorKey);

        if ($value === null || $value === '') {
            throw new InvalidArgumentException(
                "MessageChainStep [{$step->getKey()}] could not resolve anchor [{$anchorKey}].",
            );
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                "MessageChainStep [{$step->getKey()}] anchor [{$anchorKey}] is invalid.",
                previous: $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function nextDayAt(
        MessageChainStep $step,
        array $context,
    ): Carbon {
        $localTime = $this->localTime($step);
        $timezone = (string) config(
            'client.timezone',
            config('app.timezone', 'UTC'),
        );
        $anchor = $this->anchor($step, $context)
            ->timezone($timezone)
            ->startOfDay()
            ->addDays((int) $step->day_offset)
            ->setTime(
                $localTime['hour'],
                $localTime['minute'],
                $localTime['second'],
            );

        return $anchor->utc();
    }

    /**
     * @return array{hour: int, minute: int, second: int}
     */
    private function localTime(MessageChainStep $step): array
    {
        $value = trim((string) $step->local_time);

        if (preg_match(
            '/^(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)(?::(?<second>[0-5]\d))?$/',
            $value,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException(
                "MessageChainStep [{$step->getKey()}] requires a valid local time.",
            );
        }

        return [
            'hour' => (int) $matches['hour'],
            'minute' => (int) $matches['minute'],
            'second' => isset($matches['second']) && $matches['second'] !== ''
                ? (int) $matches['second']
                : 0,
        ];
    }
}