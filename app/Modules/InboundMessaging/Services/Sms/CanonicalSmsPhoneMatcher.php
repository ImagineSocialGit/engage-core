<?php

namespace App\Modules\InboundMessaging\Services\Sms;

use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class CanonicalSmsPhoneMatcher
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
    ) {}

    public function normalize(?string $phone): ?string
    {
        try {
            return $this->phoneNumberNormalizer->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function whereEquivalent(
        Builder $query,
        string $column,
        string $normalizedPhone,
    ): Builder {
        $column = trim($column);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column) !== 1) {
            throw new InvalidArgumentException(
                "Phone identity comparison column [{$column}] is invalid.",
            );
        }

        $digits = ltrim(trim($normalizedPhone), '+');

        if (preg_match('/^[1-9]\d{1,14}$/', $digits) !== 1) {
            return $query->whereRaw('1 = 0');
        }

        $trimmed = "TRIM({$column})";
        $storedDigits = "REGEXP_REPLACE({$trimmed}, '[^0-9]', '')";
        $canonicalDigits = sprintf(
            "CASE
                WHEN LEFT(%s, 1) = '+' THEN %s
                WHEN CHAR_LENGTH(%s) = 10 THEN CONCAT('1', %s)
                ELSE %s
            END",
            $trimmed,
            $storedDigits,
            $storedDigits,
            $storedDigits,
            $storedDigits,
        );

        return $query->whereRaw(
            "({$canonicalDigits}) = ?",
            [$digits],
        );
    }
}