<?php

namespace App\Modules\Webinars\Enums;

enum WebinarProviderEventType: string
{
    case Webinar = 'webinar';
    case Meeting = 'meeting';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $type): string => $type->value,
            self::cases(),
        );
    }

    public static function fromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return self::tryFrom(
            str_replace('-', '_', strtolower(trim($value))),
        );
    }

    public static function normalize(
        mixed $value,
        self $fallback = self::Webinar,
    ): string {
        return self::fromMixed($value)?->value ?? $fallback->value;
    }
}