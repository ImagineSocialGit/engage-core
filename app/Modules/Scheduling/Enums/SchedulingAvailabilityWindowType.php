<?php

namespace App\Modules\Scheduling\Enums;

enum SchedulingAvailabilityWindowType: string
{
    case Weekly = 'weekly';
    case Absolute = 'absolute';
}