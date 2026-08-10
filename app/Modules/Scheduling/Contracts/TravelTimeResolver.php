<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Data\TravelTimeEstimate;

interface TravelTimeResolver
{
    public function estimate(
        SchedulingLocationSnapshot $origin,
        SchedulingLocationSnapshot $destination,
    ): TravelTimeEstimate;
}