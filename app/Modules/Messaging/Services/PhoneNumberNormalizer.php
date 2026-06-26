<?php

namespace App\Modules\Messaging\Services;

use InvalidArgumentException;

class PhoneNumberNormalizer
{
    public function normalize(?string $phone, string $defaultCountryCode = '1'): ?string
    {
        if (! $phone) {
            return null;
        }

        $trimmed = trim($phone);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            $normalized = '+'.preg_replace('/\D+/', '', substr($trimmed, 1));

            if (! preg_match('/^\+[1-9]\d{1,14}$/', $normalized)) {
                throw new InvalidArgumentException('Phone number is not valid E.164 format.');
            }

            return $normalized;
        }

        $digits = preg_replace('/\D+/', '', $trimmed);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            $digits = $defaultCountryCode.$digits;
        }

        $normalized = '+'.$digits;

        if (! preg_match('/^\+[1-9]\d{1,14}$/', $normalized)) {
            throw new InvalidArgumentException('Phone number could not be normalized to valid E.164 format.');
        }

        return $normalized;
    }
}
