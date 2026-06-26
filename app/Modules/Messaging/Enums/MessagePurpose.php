<?php

namespace App\Modules\Messaging\Enums;

enum MessagePurpose: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}