<?php

namespace App\Modules\Messaging\Support;

use Illuminate\Support\Facades\URL;

class CtaTrackingLinkGenerator
{
    public function forScheduledMessage(
        int $scheduledMessageId,
        string $ctaKey,
        string $destination,
    ): string {
        if (! (bool) config('messaging.cta_tracking.enabled', true)
            || $scheduledMessageId < 1
            || ! self::isValidTrackingKey($ctaKey)
            || ! self::isTrackableDestination($destination)
        ) {
            return $destination;
        }

        return URL::signedRoute(
            name: 'messaging.cta.redirect',
            parameters: [
                'message' => $scheduledMessageId,
                'cta' => trim($ctaKey),
                'destination' => trim($destination),
            ],
        );
    }

    public static function isValidTrackingKey(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-z0-9][a-z0-9._-]{0,95}$/', trim($value)) === 1;
    }

    public static function isTrackableDestination(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === ''
            || strlen($value) > 2000
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return false;
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        return in_array($scheme, ['http', 'https'], true)
            && $host !== '';
    }
}