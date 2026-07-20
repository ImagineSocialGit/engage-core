<?php

return [

    'provider' => env('WEBINAR_PROVIDER', 'zoom'),


    /*
    | Consent domains are module-owned permission boundaries. Message scopes
    | remain precise runtime identities; this mapping controls which scopes share
    | one stored consent decision. Exact [webinar] plus the [webinar_] prefix
    | automatically covers current and future Webinar-owned message scopes.
    */
    'consent_domains' => [
        'webinar' => [
            'topic' => 'webinars and webinar follow-up',
            'scopes' => [
                'webinar',
            ],
            'scope_prefixes' => [
                'webinar_',
            ],
            'opt_in' => [],
        ],
    ],

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

        'registration' => env('WEBINAR_REGISTRATION_QUEUE', 'webinars'),

        'registration_recovery' => 'default',

        'webhooks' => env('WEBINAR_WEBHOOK_QUEUE', 'webhooks'),

        'notifications' => env('WEBINAR_REMINDER_QUEUE', 'notifications'),

        'reminders' => env('WEBINAR_REMINDER_QUEUE', 'notifications'),

        'confirmation_messages' => env('WEBINAR_CONFIRMATION_MESSAGE_QUEUE', 'notifications'),

        'followups' => env('WEBINAR_FOLLOWUP_QUEUE', 'notifications'),
    ],


    'registration' => [

        'require_unique_email_per_webinar' => true,

        'rate_limits' => [
            'per_ip_per_minute' => 10,
            'per_ip_per_hour' => 100,
            'per_email_per_hour' => 6,
            'per_phone_per_hour' => 6,
        ],

        /*
        | This is intentionally lightweight friction rather than CAPTCHA. The
        | server still treats the honeypot and JavaScript proofs as advisory
        | layers behind CSRF, signed URLs, validation, and rate limiting.
        */
        'bot_protection' => [
            'enabled' => true,
            'ready_value' => 'ready',
            'interaction_value' => 'human',
        ],

        'thank_you' => [
            'link_expiration_minutes' => 10080,
            'refresh_seconds' => 5,
        ],

        'join_confirmation' => [
            'link_expiration_minutes' => 30,
        ],

        /*
        | Registration finalization is staged in WebinarRegistration.meta before
        | any provider or Messaging work begins. The scheduled recovery pass can
        | requeue abandoned local work, but never retries an ambiguous provider
        | submission whose remote outcome is unknown.
        */
        'finalization' => [
            'job_tries' => 5,
            'job_backoff_seconds' => [60, 300, 900, 3600],
            'retry_delay_seconds' => 60,
            'queue_failure_retry_seconds' => 60,
            'queue_stale_after_seconds' => 300,
            'processing_stale_after_seconds' => 600,
            'provider_claim_stale_after_seconds' => 600,
            'in_progress_release_seconds' => 30,
            'overlap_expiry_seconds' => 900,
            'recovery_batch_size' => 100,
            'recovery_cron' => '* * * * *',
        ],
    ],

    'webhooks' => [

        'validate_signature' => true,

        'max_timestamp_drift_seconds' => 300,

    ],

];