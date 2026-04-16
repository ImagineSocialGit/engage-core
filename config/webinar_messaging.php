<?php

return [

    'message_types' => [

        'reminder_10d' => [
            'email' => true,
            'sms' => true,
        ],

        'reminder_7d' => [
            'email' => true,
            'sms' => true,
        ],

        'reminder_24h' => [
            'email' => true,
            'sms' => true,
        ],

        'reminder_30m' => [
            'email' => true,
            'sms' => true,
        ],

        'reminder_10m' => [
            'email' => true,
            'sms' => true,
        ],

        'late_joiner_5m' => [
            'email' => true,
            'sms' => true,
        ],

    ],

    'testing' => [
        'enabled' => env('WEBINAR_REMINDER_TESTING', false),

        // delays in seconds from "right now"
        'delays' => [
            'reminder_10d' => 60,
            'reminder_7d' => 120,
            'reminder_24h' => 180,
            'reminder_30m' => 240,
            'reminder_10m' => 300,
            'late_joiner_5m' => 360,
        ],
    ],

];