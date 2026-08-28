<?php

$publicUrl = env('SCHEDULING_APP_URL');
$publicUrlConfigured = $publicUrl !== null && $publicUrl !== '';
$normalizedPublicUrl = null;
$publicHost = null;
$publicScheme = null;

if ($publicUrlConfigured && is_string($publicUrl)) {
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

    'consent_domains' => [
        'scheduling_appointments' => [
            'topic' => 'appointment confirmations, reminders, scheduling updates, and appointment-related follow-up',
            'scopes' => [
                'scheduling_appointments',
            ],
            'scope_prefixes' => [],
            'opt_in' => [],
        ],
    ],

    'communications' => [
        'chain_key' => 'scheduling_appointment_communications',
        'default_subject' => 'Appointment reminder',
        'default_message' => "Hello {first_name}! You have an appointment on:\n\n{appointment_date} at {appointment_time_with_timezone}.\n\n{appointment_location_or_method}\n\nThank you!",
    ],

    'public' => [
        'configured' => $publicUrlConfigured,
        'enabled' => $normalizedPublicUrl !== null,
        'url' => $normalizedPublicUrl,
        'host' => $publicHost,
        'scheme' => $publicScheme,
        'availability_max_days' => 31,
        'reservation_rate_limit_per_minute' => 12,
        'hold_review_rate_limit_per_minute' => 60,
        'reporting_enabled' => true,

        /*
         * Client config may override any presentation value. Null colors fall
         * back to the selected client theme and then the neutral defaults.
         */
        'presentation' => [
            'brand_name' => null,
            'logo_url' => null,
            'heading' => 'Schedule an appointment',
            'intro' => 'Choose a service and a time that works for you.',
            'primary_color' => null,
            'accent_color' => null,
            'surface_color' => '#ffffff',
            'background_color' => '#f6f7f8',
            'page_revision' => 'scheduling-public-v2',
            'disclosure_version' => '2',
            'consent_text' => 'By providing your email address or phone number, you agree to receive appointment confirmations, reminders, scheduling updates, and other messages related to this appointment. Message and data rates may apply. Reply STOP to opt out of texts or HELP for help.',
        ],

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