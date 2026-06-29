<?php

return [

    'enabled' => config('client.modules.messaging', env('SMS_ENABLED', true)),

    'provider' => env('SMS_PROVIDER', 'telnyx'),

    'from' => [
        'transactional' => env('SMS_FROM_TRANSACTIONAL', env('SMS_FROM', env('TWILIO_FROM', env('TELNYX_FROM')))),
        'marketing' => env('SMS_FROM_MARKETING', env('SMS_FROM', env('TWILIO_FROM', env('TELNYX_FROM')))),
    ],

    'providers' => [

        'twilio' => [
            'from' => [
                'transactional' => env('TWILIO_FROM_TRANSACTIONAL', env('SMS_FROM_TRANSACTIONAL', env('SMS_FROM', env('TWILIO_FROM')))),
                'marketing' => env('TWILIO_FROM_MARKETING', env('SMS_FROM_MARKETING', env('SMS_FROM', env('TWILIO_FROM')))),
            ],
        ],

        'telnyx' => [
            'from' => [
                'transactional' => env('TELNYX_FROM_TRANSACTIONAL', env('SMS_FROM_TRANSACTIONAL', env('TELNYX_FROM', env('SMS_FROM')))),
                'marketing' => env('TELNYX_FROM_MARKETING', env('SMS_FROM_MARKETING', env('TELNYX_FROM', env('SMS_FROM')))),
            ],

            'profile_ids' => [
                'marketing' => env('MESSAGING_SMS_MARKETING_PROFILE_ID'),
                'transactional' => env('MESSAGING_SMS_TRANSACTIONAL_PROFILE_ID'),
            ],

            'webhooks' => [
                'inbound_event_types' => [
                    'message.received',
                ],
            ],
        ],

    ],

    'queues' => [
        'default' => env('SMS_QUEUE', 'sms'),
    ],

    'rate_limits' => [
        'per_ip_per_hour' => env('SMS_RATE_LIMIT_PER_IP_PER_HOUR', 5),
        'per_phone_per_day' => env('SMS_RATE_LIMIT_PER_PHONE_PER_DAY', 10),
    ],

    'cooldowns' => [
        'duplicate_window_minutes' => env('SMS_DUPLICATE_WINDOW_MINUTES', 15),
    ],

    'monitoring' => [
        'daily_send_alert_threshold' => env('SMS_DAILY_ALERT_THRESHOLD', 500),

        'daily_send_hard_limit' => env('SMS_DAILY_HARD_LIMIT', 2000),
    ],

];