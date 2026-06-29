<?php

return [

    'enabled' => config('client.webinars.enabled', env('WEBINARS_ENABLED', true)),

    'provider' => env('WEBINAR_PROVIDER', 'zoom'),

    'providers' => [
        'zoom' => [
            'provider' => App\Integrations\Webinars\Zoom\ZoomWebinarProvider::class,
            'base_url' => env('ZOOM_BASE_URL', 'https://api.zoom.us/v2'),
            'oauth_url' => env('ZOOM_OAUTH_URL', 'https://zoom.us/oauth/token'),
            'oauth_token_ttl_seconds' => env('ZOOM_OAUTH_TOKEN_TTL_SECONDS', 3500),

            'webhook_events' => [
                'webinar.ended' => 'webinar.ended',
                'webinar.completed' => 'webinar.ended',
                'recording.completed' => 'webinar.recording_completed',
            ],
        ],
    ],

    'queues' => [

        'registrations' => env('WEBINAR_REGISTRATION_QUEUE', 'webinars'),

        'webhooks' => env('WEBINAR_WEBHOOK_QUEUE', 'webhooks'),

        'notifications' => env('WEBINAR_REMINDER_QUEUE', 'notifications'),

        'reminders' => env('WEBINAR_REMINDER_QUEUE', 'notifications'),

        'confirmation_messages' => env('WEBINAR_CONFIRMATION_MESSAGE_QUEUE', 'notifications'),

        'followups' => env('WEBINAR_FOLLOWUP_QUEUE', 'notifications'),
    ],

    'registration' => [

        'require_unique_email_per_webinar' => true,

        'cooldowns' => [
            'per_email_minutes' => 15,
            'per_phone_minutes' => 15,
            'per_ip_minutes' => 15,
        ],

        'rate_limits' => [

            'per_ip_per_minute' => 400,

            'per_email_per_hour' => 500,
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