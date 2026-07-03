<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [

    'confirmations' => [
        [
            'dispatch_key' => 'registration_created',

            'conditions' => [
                [
                    'field' => 'webinar.starts_at',
                    'operator' => 'at_least_minutes_from_now',
                    'value' => 30,
                ],
            ],

            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'confirmation_messages',

            'schedule' => [
                'type' => 'delay',
                'minutes' => 15,
            ],

            'payload' => [
                'message' => "You're registered for {webinar_title} on {webinar_start_date} at {webinar_start_time}. Join here: {webinar_join_url}",
            ],
        ],
    ],

    'opt_ins' => [
        [
            'dispatch_key' => 'consent_granted',
            'timing' => 'immediate',
            'payload_class' => SmsPayload::class,
            'queue' => 'opt_in_messages',

            'payload' => [
                'message' => 'Thanks for subscribing to receive webinar-related messages! You will receive confirmation details shortly. Reply HELP for help. Message frequency may vary. Msg&data rates may apply. Reply STOP to opt out.',
            ],
        ],
    ],

    'reminders' => [
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -14400,
            ],

            'payload' => [
                'message' => '{webinar_title} is 10 days away on {webinar_start_date} at {webinar_start_time}. Join here: {webinar_join_url}',
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -10080,
            ],

            'payload' => [
                'message' => '{webinar_title} is 1 week away. It starts {webinar_start_date} at {webinar_start_time}. Join here: {webinar_join_url}',
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -1440,
            ],

            'payload' => [
                'message' => 'Reminder: {webinar_title} is tomorrow at {webinar_start_time}. Join here: {webinar_join_url}',
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -30,
            ],

            'payload' => [
                'message' => '{webinar_title} starts in 30 minutes at {webinar_start_time}. Join here: {webinar_join_url}',
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -10,
            ],

            'payload' => [
                'message' => '{webinar_title} starts in 10 minutes. Join here: {webinar_join_url}',
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'skip_when_join_clicked' => true,
            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => 0,
            ],

            'payload' => [
                'message' => '{webinar_title} is live! Join here: {webinar_join_url}',
            ],
        ],
    ],

    'post_attended' => [
        [
            'dispatch_key' => 'webinar_ended',
            'conditions' => [
                [
                    'field' => 'webinar_registration.attended_at',
                    'operator' => 'filled',
                ],
            ],
            'timing' => 'immediate',
            'payload_class' => SmsPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'message' => "Thanks for joining {webinar_title}. We'll send your replay and next steps soon.",
            ],
        ],
    ],

    'post_missed' => [
        [
            'dispatch_key' => 'webinar_ended',
            'conditions' => [
                [
                    'field' => 'webinar_registration.attended_at',
                    'operator' => 'blank',
                ],
            ],
            'timing' => 'immediate',
            'payload_class' => SmsPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'message' => "Sorry we missed you for {webinar_title}. We'll follow up with next steps soon.",
            ],
        ],
    ],

];
