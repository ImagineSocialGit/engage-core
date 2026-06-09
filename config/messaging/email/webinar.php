<?php

use App\Messaging\Payloads\Webinars\Email\WebinarConfirmationEmailPayload;
use App\Messaging\Payloads\Webinars\Email\WebinarReminderEmailPayload;

return [

    'registration_confirmation' => [
        'enabled' => true,
        'scope' => 'webinar',
        'purpose' => 'transactional',
        'payload_class' => WebinarConfirmationEmailPayload::class,
        'queue' => 'confirmation_messages',
        'subject' => 'You’re registered: :webinar_title',
    ],

    'reminders' => [
        'enabled' => true,
        'scope' => 'webinar',
        'purpose' => 'transactional',
        'payload_class' => WebinarReminderEmailPayload::class,
        'queue' => 'reminders',

        'variants' => [
            'reminder_10d' => [
                'subject' => 'Your webinar is coming up in 10 days',
                'offset_minutes_before_start' => 14400,
            ],
            'reminder_7d' => [
                'subject' => 'Your webinar is one week away',
                'offset_minutes_before_start' => 10080,
            ],
            'reminder_24h' => [
                'subject' => 'Your webinar is tomorrow',
                'offset_minutes_before_start' => 1440,
            ],
            'reminder_30m' => [
                'subject' => 'Your webinar starts in 30 minutes',
                'offset_minutes_before_start' => 30,
            ],
            'reminder_10m' => [
                'subject' => 'Your webinar starts in 10 minutes',
                'offset_minutes_before_start' => 10,
            ],
            'late_joiner_5m' => [
                'subject' => 'Your webinar is live now',
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
                'payload_class' => App\Messaging\Payloads\Webinars\Email\WebinarFollowUpEmailPayload::class,
            ],

            'missed' => [
                'message_type' => 'webinar_post_missed',
                'payload_class' => App\Messaging\Payloads\Webinars\Email\WebinarFollowUpEmailPayload::class,
            ],
        ],
    ],

    'opt_in' => [
        'enabled' => true,
        'scope' => 'webinar',
        'purpose' => 'transactional',
        'message_type' => 'webinar_transactional_opt_in',
        'payload_class' => App\Messaging\Payloads\Marketing\Email\MarketingEmailPayload::class,
        'queue' => 'opt_in_messages',

        'payload' => [
            'subject' => 'You’re subscribed to webinar emails',
            'body' => 'Thanks for subscribing to receive webinar-related emails. You can opt out of these messages using the link in any webinar email.',
        ],
    ],

];