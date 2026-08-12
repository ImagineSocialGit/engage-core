<?php

namespace App\Modules\Webinars\Services;

use DateTimeZone;
use Throwable;

final class WebinarTimezoneResolver
{
    public function resolve(mixed $timezone = null): string
    {
        foreach ([
            $timezone,
            config('client.timezone'),
            'UTC',
        ] as $candidate) {
            $candidate = $this->normalize($candidate);

            if ($candidate !== null && $this->isValid($candidate)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    private function normalize(mixed $timezone): ?string
    {
        if (! is_string($timezone)) {
            return null;
        }

        $timezone = trim($timezone);

        return $timezone !== '' ? $timezone : null;
    }

    private function isValid(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}