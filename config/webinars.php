<?php

return [

    'enabled' => env('WEBINARS_ENABLED', true),

    'provider' => env('WEBINAR_PROVIDER', 'zoom'),

    'providers' => [
        'zoom' => [
            'base_url' => env('ZOOM_BASE_URL', 'https://api.zoom.us/v2'),
            'oauth_url' => env('ZOOM_OAUTH_URL', 'https://zoom.us/oauth/token'),
            'oauth_token_cache_key' => env('ZOOM_OAUTH_TOKEN_CACHE_KEY', 'zoom_access_token'),
            'oauth_token_ttl_seconds' => env('ZOOM_OAUTH_TOKEN_TTL_SECONDS', 3500),
        ],
    ],

    'managed_by' => env('WEBINAR_MANAGED_BY', 'client'),

    'queues' => [

        'registrations' => env('WEBINAR_REGISTRATION_QUEUE', 'webinars'),

        'webhooks' => env('WEBINAR_WEBHOOK_QUEUE', 'webhooks'),

        'reminders' => env('WEBINAR_REMINDER_QUEUE', 'notifications'),

        'confirmation_messages' => env('WEBINAR_CONFIRMATION_MESSAGE_QUEUE', 'notifications'),

        'followups' => env('WEBINAR_FOLLOWUP_QUEUE', 'notifications'),
    ],

    'reminders' => [

        'enabled' => true,

        'schedule' => [
            '10_days',
            '7_days',
            '24_hours',
            '30_minutes',
            '10_minutes',
        ],
    ],

    'registration' => [

        'require_unique_email_per_webinar' => true,

        'cooldowns' => [
            'per_email_minutes' => 15,
            'per_phone_minutes' => 15,
            'per_ip_minutes' => 15,
        ],
    ],

    'webhooks' => [

        'validate_signature' => true,

        'max_timestamp_drift_seconds' => 300,

        'replay_cache_ttl_seconds' => 600,
    ],

    'cache' => [

        'next_webinar_ttl_seconds' => 300,

        'webinar_page_ttl_seconds' => 300,
    ],

];
