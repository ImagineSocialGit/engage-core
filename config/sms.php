<?php

return [

    'enabled' => env('SMS_ENABLED', true),

    'provider' => env('SMS_PROVIDER', 'twilio'),

    'managed_by' => env('SMS_MANAGED_BY', 'platform'),

    'from' => env('SMS_FROM', env('TWILIO_FROM')),

    'providers' => [

        'twilio' => [
            'from' => env('SMS_FROM', env('TWILIO_FROM')),
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

    'compliance' => [
        'require_opt_in' => true,
        'respect_stop_requests' => true,
    ],

    'monitoring' => [
        'daily_send_alert_threshold' => env('SMS_DAILY_ALERT_THRESHOLD', 500),

        'daily_send_hard_limit' => env('SMS_DAILY_HARD_LIMIT', 2000),
    ],

];
