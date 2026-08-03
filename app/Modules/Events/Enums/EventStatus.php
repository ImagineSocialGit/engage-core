<?php

namespace App\Modules\Events\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Upcoming = 'upcoming';
    case Postponed = 'postponed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}