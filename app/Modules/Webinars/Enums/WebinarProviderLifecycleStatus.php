<?php

namespace App\Modules\Webinars\Enums;

enum WebinarProviderLifecycleStatus: string
{
    case Active = 'active';
    case Missing = 'missing';
    case Archived = 'archived';

    public static function normalize(mixed $value): string
    {
        if ($value instanceof self) {
            return $value->value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (self::tryFrom($normalized) instanceof self) {
                return $normalized;
            }
        }

        return self::Active->value;
    }
}