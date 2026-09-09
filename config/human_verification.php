<?php

use App\Integrations\HumanVerification\Turnstile\TurnstileHumanVerificationProvider;

return [
    'enabled' => filter_var(
        env('PUBLIC_HUMAN_VERIFICATION_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),

    'provider' => env('PUBLIC_HUMAN_VERIFICATION_PROVIDER', 'turnstile'),

    // A successful provider challenge becomes a short-lived anonymous-session
    // grant for the same public surface and hostname. This is deliberately
    // longer than a single provider token so multi-step journeys do not ask the
    // visitor to prove humanity on every POST.
    'grant_ttl_seconds' => (int) env(
        'PUBLIC_HUMAN_VERIFICATION_GRANT_TTL_SECONDS',
        1800,
    ),

    'response_field' => 'cf-turnstile-response',

    'surfaces' => [
        'webinars' => [
            'enabled' => true,
            'action' => 'webinars',
        ],
        'scheduling' => [
            'enabled' => true,
            'action' => 'scheduling',
        ],
        'messaging_permissions' => [
            'enabled' => true,
            'action' => 'messaging_permissions',
        ],
    ],

    'providers' => [
        'turnstile' => [
            'driver' => TurnstileHumanVerificationProvider::class,
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret_key' => env('TURNSTILE_SECRET_KEY'),
            'siteverify_url' => TurnstileHumanVerificationProvider::SITEVERIFY_URL,
            'timeout_seconds' => (int) env('TURNSTILE_TIMEOUT_SECONDS', 5),
            'connect_timeout_seconds' => (int) env('TURNSTILE_CONNECT_TIMEOUT_SECONDS', 2),
            'retry_attempts' => (int) env('TURNSTILE_RETRY_ATTEMPTS', 2),
            'retry_sleep_milliseconds' => (int) env('TURNSTILE_RETRY_SLEEP_MILLISECONDS', 100),
            'challenge_max_age_seconds' => 300,
        ],
    ],
];