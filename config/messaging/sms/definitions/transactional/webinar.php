<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [
    'confirmations' => [
        [
            'key' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_type' => 'confirmation',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => 'confirmation_messages',
            'payload' => [
                'message' => "You're registered for {webinar_title} on {webinar_start_date} at {webinar_start_time}. Join here: {webinar_join_url}",
            ],
        ],
    ],

    'opt_ins' => [
        [
            'key' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => 'opt_in_messages',
            'payload' => [
                'message' => 'Thanks for subscribing to webinar-related messages. You will receive access details, reminders, and replay information. Reply HELP for help. Msg&data rates may apply. Reply STOP to opt out.',
            ],
        ],
    ],

    'reminders' => [
        [
            'key' => 'reminder_1_week',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'message' => '{webinar_title} is one week away on {webinar_start_date} at {webinar_start_time}. Join here: {webinar_join_url}',
            ],
        ],
        [
            'key' => 'reminder_1_day',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'message' => 'Reminder: {webinar_title} is tomorrow at {webinar_start_time}. Join here: {webinar_join_url}',
            ],
        ],
        [
            'key' => 'reminder_30_minute',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'message' => '{webinar_title} starts in 30 minutes at {webinar_start_time}. Join here: {webinar_join_url}',
            ],
        ],
        [
            'key' => 'reminder_live',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'message' => '{webinar_title} is live now. Join here: {webinar_join_url}',
            ],
        ],
    ],

    'post_attended' => [
        [
            'key' => 'post_attended',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_attended',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => 'post_event',
            'payload' => [
                'message' => 'Thanks for joining {webinar_title}. Watch the replay here: {webinar_playback_url}',
            ],
        ],
    ],

    'post_missed' => [
        [
            'key' => 'post_missed',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_missed',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => 'post_event',
            'payload' => [
                'message' => 'Sorry we missed you at {webinar_title}. Watch the replay here: {webinar_playback_url}',
            ],
        ],
    ],
];
