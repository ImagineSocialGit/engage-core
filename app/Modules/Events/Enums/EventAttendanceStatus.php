<?php

namespace App\Modules\Events\Enums;

enum EventAttendanceStatus: string
{
    case Attended = 'attended';
    case DidNotAttend = 'did_not_attend';

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