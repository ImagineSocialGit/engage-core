<?php

namespace App\Modules\Scheduling\Data;

use InvalidArgumentException;

final readonly class TravelTimeEstimate
{
    public function __construct(
        public int $minutes,
        public string $source,
    ) {
        if ($minutes < 0 || $minutes > 1440) {
            throw new InvalidArgumentException(
                'Scheduling travel-time estimates must be between 0 and 1440 minutes.',
            );
        }

        if (trim($source) === '' || mb_strlen(trim($source)) > 80) {
            throw new InvalidArgumentException(
                'Scheduling travel-time estimates require a non-empty source no longer than 80 characters.',
            );
        }
    }
}