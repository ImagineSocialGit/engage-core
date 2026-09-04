<?php

namespace App\Modules\Webinars\Data;

final readonly class WebinarSeriesRemovalPlan
{
    public function __construct(
        public int $sessionCount,
        public int $waitlistSignupCount,
        public int $suppressionCount,
    ) {}

    public function canDelete(): bool
    {
        return ! $this->hasHistory();
    }

    public function hasHistory(): bool
    {
        return $this->sessionCount > 0
            || $this->waitlistSignupCount > 0
            || $this->suppressionCount > 0;
    }
}