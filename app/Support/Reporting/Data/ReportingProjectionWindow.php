<?php

namespace App\Support\Reporting\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportingProjectionWindow
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {
        if ($this->endsAt->lessThan($this->startsAt)) {
            throw new InvalidArgumentException(
                'Reporting projection window end cannot precede its start.',
            );
        }
    }
}