<?php

namespace App\Modules\Scheduling\Data;

final readonly class TravelFeasibility
{
    public function __construct(
        public bool $feasible,
        public ?int $minutesBefore = null,
        public ?int $minutesAfter = null,
        public ?string $sourceBefore = null,
        public ?string $sourceAfter = null,
        public ?string $reason = null,
    ) {}

    public static function unconstrained(): self
    {
        return new self(feasible: true);
    }

    public function totalMinutes(): ?int
    {
        if ($this->minutesBefore === null && $this->minutesAfter === null) {
            return null;
        }

        return ($this->minutesBefore ?? 0) + ($this->minutesAfter ?? 0);
    }
}