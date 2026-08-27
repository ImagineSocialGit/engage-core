<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Data\BookableSlot;
use InvalidArgumentException;

class SchedulingAvailableStartRangeBuilder
{
    /**
     * @param array<int, BookableSlot> $slots
     * @return array<int, array{
     *     starts_at: \Carbon\CarbonImmutable,
     *     last_start_at: \Carbon\CarbonImmutable,
     *     display_timezone: string,
     *     interval_minutes: int,
     *     slot_count: int,
     *     capacity: int,
     *     remaining_capacity: int,
     *     slots: array<int, BookableSlot>
     * }>
     */
    public function build(array $slots, int $intervalMinutes): array
    {
        if ($intervalMinutes < 1) {
            throw new InvalidArgumentException(
                'Available start-time grouping requires a positive slot interval.',
            );
        }

        if ($slots === []) {
            return [];
        }

        foreach ($slots as $slot) {
            if (! $slot instanceof BookableSlot) {
                throw new InvalidArgumentException(
                    'Available start-time grouping accepts BookableSlot values only.',
                );
            }
        }

        usort(
            $slots,
            static fn (BookableSlot $left, BookableSlot $right): int =>
                $left->startsAt->getTimestamp() <=> $right->startsAt->getTimestamp(),
        );

        $ranges = [];
        $current = [];
        $previous = null;

        foreach ($slots as $slot) {
            $continues = $previous instanceof BookableSlot
                && $this->samePresentationContext($previous, $slot)
                && $previous->startsAt
                    ->addMinutes($intervalMinutes)
                    ->equalTo($slot->startsAt);

            if (! $continues && $current !== []) {
                $ranges[] = $this->range($current, $intervalMinutes);
                $current = [];
            }

            $current[] = $slot;
            $previous = $slot;
        }

        if ($current !== []) {
            $ranges[] = $this->range($current, $intervalMinutes);
        }

        return $ranges;
    }

    private function samePresentationContext(
        BookableSlot $left,
        BookableSlot $right,
    ): bool {
        return $left->bookableServiceId === $right->bookableServiceId
            && $left->schedulingHostId === $right->schedulingHostId
            && $left->displayTimezone === $right->displayTimezone
            && $left->capacity === $right->capacity
            && $left->remainingCapacity === $right->remainingCapacity;
    }

    /**
     * @param array<int, BookableSlot> $slots
     * @return array{
     *     starts_at: \Carbon\CarbonImmutable,
     *     last_start_at: \Carbon\CarbonImmutable,
     *     display_timezone: string,
     *     interval_minutes: int,
     *     slot_count: int,
     *     capacity: int,
     *     remaining_capacity: int,
     *     slots: array<int, BookableSlot>
     * }
     */
    private function range(array $slots, int $intervalMinutes): array
    {
        $first = $slots[0];
        $last = $slots[array_key_last($slots)];

        return [
            'starts_at' => $first->startsAt,
            'last_start_at' => $last->startsAt,
            'display_timezone' => $first->displayTimezone,
            'interval_minutes' => $intervalMinutes,
            'slot_count' => count($slots),
            'capacity' => $first->capacity,
            'remaining_capacity' => $first->remainingCapacity,
            'slots' => $slots,
        ];
    }
}