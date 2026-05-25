<?php

namespace App\Enums;

enum MessageChannel: string
{
    case Email = 'email';
    case Sms = 'sms';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}