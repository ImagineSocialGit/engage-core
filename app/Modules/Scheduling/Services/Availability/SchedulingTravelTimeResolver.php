<?php

namespace App\Modules\Scheduling\Services\Availability;

use App\Modules\Scheduling\Contracts\TravelTimeResolver;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Data\TravelTimeEstimate;
use Illuminate\Contracts\Container\Container;

final class SchedulingTravelTimeResolver
{
    /** @var array<string, TravelTimeEstimate> */
    private array $estimates = [];

    public function __construct(
        private readonly Container $container,
        private readonly ConservativeTravelTimeResolver $fallback,
    ) {}

    public function estimate(
        SchedulingLocationSnapshot $origin,
        SchedulingLocationSnapshot $destination,
    ): TravelTimeEstimate {
        $key = $this->cacheKey($origin, $destination);

        if (isset($this->estimates[$key])) {
            return $this->estimates[$key];
        }

        $estimate = $this->container->bound(TravelTimeResolver::class)
            ? $this->container->make(TravelTimeResolver::class)->estimate(
                $origin,
                $destination,
            )
            : $this->fallback->estimate($origin, $destination);

        return $this->estimates[$key] = $estimate;
    }

    private function cacheKey(
        SchedulingLocationSnapshot $origin,
        SchedulingLocationSnapshot $destination,
    ): string {
        return hash('sha256', serialize([
            $origin->type,
            $origin->details,
            $destination->type,
            $destination->details,
        ]));
    }
}