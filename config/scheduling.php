<?php

$publicUrl = env('SCHEDULING_APP_URL');
$normalizedPublicUrl = null;
$publicHost = null;
$publicScheme = null;

if (is_string($publicUrl) && trim($publicUrl) !== '') {
    $candidate = trim($publicUrl);
    $parts = parse_url($candidate);

    $scheme = is_array($parts) && is_string($parts['scheme'] ?? null)
        ? strtolower(trim($parts['scheme']))
        : null;
    $host = is_array($parts) && is_string($parts['host'] ?? null)
        ? strtolower(trim($parts['host']))
        : null;
    $path = is_array($parts) && is_string($parts['path'] ?? null)
        ? trim($parts['path'])
        : '';
    $port = is_array($parts) && is_int($parts['port'] ?? null)
        ? $parts['port']
        : null;

    $hasUnsupportedParts = is_array($parts) && (
        array_key_exists('user', $parts)
        || array_key_exists('pass', $parts)
        || array_key_exists('query', $parts)
        || array_key_exists('fragment', $parts)
    );

    if (in_array($scheme, ['http', 'https'], true)
        && is_string($host)
        && $host !== ''
        && in_array($path, ['', '/'], true)
        && ! $hasUnsupportedParts
    ) {
        $publicScheme = $scheme;
        $publicHost = $host;
        $normalizedPublicUrl = $scheme.'://'.$host.($port !== null ? ':'.$port : '');
    }
}

return [

    'public' => [
        'enabled' => $normalizedPublicUrl !== null,
        'url' => $normalizedPublicUrl,
        'host' => $publicHost,
        'scheme' => $publicScheme,
        'availability_max_days' => 31,
        'reservation_rate_limit_per_minute' => 12,
        'hold_review_rate_limit_per_minute' => 60,

        'destination_verification' => [
            'code_digits' => 6,
            'challenge_ttl_seconds' => 300,
            'proof_ttl_seconds' => 300,
            'max_code_attempts' => 5,
            'max_sends_per_challenge' => 3,
            'resend_cooldown_seconds' => 30,
            'rate_limits' => [
                'per_ip_per_hour' => 20,
                'per_destination_per_hour' => 6,
                'per_offer_session_per_hour' => 8,
                'per_challenge_per_hour' => 4,
            ],
        ],
    ],


    'travel' => [
        'maximum_minutes' => 240,
        'conservative_minutes' => 45,
    ],

    'reschedule_suggestions' => [
        'lookahead_days' => 14,
        'limit' => 6,
    ],

    'slot_offers' => [
        'ttl_seconds' => 300,
    ],

    'booking_holds' => [
        'ttl_seconds' => 600,
        'expiration_batch_size' => 500,
    ],

];