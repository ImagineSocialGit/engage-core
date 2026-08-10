<?php

namespace App\Modules\Scheduling\Services\Availability;

use App\Modules\Scheduling\Contracts\TravelTimeResolver;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Data\TravelTimeEstimate;

final class ConservativeTravelTimeResolver implements TravelTimeResolver
{
    public function estimate(
        SchedulingLocationSnapshot $origin,
        SchedulingLocationSnapshot $destination,
    ): TravelTimeEstimate {
        if (! $origin->isPhysical() || ! $destination->isPhysical()) {
            return new TravelTimeEstimate(
                minutes: 0,
                source: 'non_physical',
            );
        }

        if ($this->sameAddress($origin, $destination)) {
            return new TravelTimeEstimate(
                minutes: 0,
                source: 'same_address',
            );
        }

        $maximum = max(
            0,
            min(1440, (int) config('scheduling.travel.maximum_minutes', 240)),
        );
        $fallback = max(
            0,
            min($maximum, (int) config('scheduling.travel.conservative_minutes', 45)),
        );

        return new TravelTimeEstimate(
            minutes: $fallback,
            source: 'conservative_fallback',
        );
    }

    private function sameAddress(
        SchedulingLocationSnapshot $origin,
        SchedulingLocationSnapshot $destination,
    ): bool {
        $originAddress = data_get($origin->details, 'address');
        $destinationAddress = data_get($destination->details, 'address');

        return is_array($originAddress)
            && is_array($destinationAddress)
            && $originAddress === $destinationAddress;
    }
}