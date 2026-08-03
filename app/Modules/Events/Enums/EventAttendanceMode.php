<?php

namespace App\Modules\Events\Enums;

enum EventAttendanceMode: string
{
    case Physical = 'physical';
    case Virtual = 'virtual';
    case Hybrid = 'hybrid';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $mode): string => $mode->value,
            self::cases(),
        );
    }
}