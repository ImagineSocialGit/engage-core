<?php

use App\Messaging\Payloads\Webinars\Sms\WebinarConfirmationSmsPayload;
use App\Messaging\Payloads\Webinars\Sms\WebinarReminderSmsPayload;
use App\Messaging\Payloads\Webinars\Sms\WebinarTransactionalSmsPayload;

return [

    'registration_confirmation' => [
        'enabled' => true,
        'scope' => 'webinar',
        'purpose' => 'transactional',
        'payload_class' => WebinarConfirmationSmsPayload::class,
        'queue' => 'confirmation_messages',
    ],

    'transactional_opt_in' => [
        'enabled' => true,
        'scope' => 'webinar',
        'message_type' => 'webinar_transactional_opt_in',
        'purpose' => 'transactional',
        'payload_class' => WebinarTransactionalSmsPayload::class,
        'queue' => 'opt_in_messages',
        'message' => 'Thanks for subscribing to receive webinar-related messages! Reply HELP for help. Message frequency may vary. Msg&data rates may apply. Reply STOP to opt out.',
    ],

    'reminders' => [
        'enabled' => true,
        'scope' => 'webinar',
        'purpose' => 'transactional',
        'payload_class' => WebinarReminderSmsPayload::class,
        'queue' => 'reminders',

        'variants' => [
            '10_days' => [
                'message_type' => 'reminder_10d',
                'offset_minutes_before_start' => 14400,
            ],
            '7_days' => [
                'message_type' => 'reminder_7d',
                'offset_minutes_before_start' => 10080,
            ],
            '24_hours' => [
                'message_type' => 'reminder_24h',
                'offset_minutes_before_start' => 1440,
            ],
            '30_minutes' => [
                'message_type' => 'reminder_30m',
                'offset_minutes_before_start' => 30,
            ],
            '10_minutes' => [
                'message_type' => 'reminder_10m',
                'offset_minutes_before_start' => 10,
            ],
            '5_minutes_after_start' => [
                'message_type' => 'late_joiner_5m',
                'offset_minutes_before_start' => -5,
            ],
        ],
    ],

    'follow_up' => [
        'enabled' => true,
        'scope' => 'webinar',
        'purpose' => 'transactional',
        'queue' => 'notifications',

        'variants' => [
            'replay' => [
                'message_type' => 'webinar_post_replay',
                'payload_class' => App\Messaging\Payloads\Webinars\Sms\WebinarFollowUpSmsPayload::class,
            ],

            'missed' => [
                'message_type' => 'webinar_post_missed',
                'payload_class' => App\Messaging\Payloads\Webinars\Sms\WebinarFollowUpSmsPayload::class,
            ],
        ],
    ],

    'overrides' => [

        'homebuyer-game-plan' => [

            'reminders' => [
                'variants' => [
                    '24_hours' => [
                        'enabled' => false,
                    ],

                    '30_minutes' => [
                        'offset_minutes_before_start' => 45,
                    ],
                ],
            ],

        ],

    ],

];