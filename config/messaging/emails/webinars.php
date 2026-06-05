<?php

return [
    'registration_confirmation' => [
        'enabled' => true,
        'subject' => 'You’re registered: :webinar_title',
    ],

    'reminders' => [
        'enabled' => true,

        'messages' => [
            'reminder_10d' => [
                'subject' => 'Your webinar is coming up in 10 days',
            ],
            'reminder_7d' => [
                'subject' => 'Your webinar is one week away',
            ],
            'reminder_24h' => [
                'subject' => 'Your webinar is tomorrow',
            ],
            'reminder_30m' => [
                'subject' => 'Your webinar starts in 30 minutes',
            ],
            'reminder_10m' => [
                'subject' => 'Your webinar starts in 10 minutes',
            ],
            'late_joiner_5m' => [
                'subject' => 'Your webinar is live now',
            ],
        ],
    ],

    'post_follow_up' => [
        'enabled' => true,

        'missed' => [
            'subject' => 'Sorry we missed you: :webinar_title',
        ],

        'replay' => [
            'subject' => 'Thanks for attending: :webinar_title',
        ],
    ],

    'waitlist_scheduled' => [
        'enabled' => true,
        'subject' => 'New webinar scheduled: :webinar_title',
    ],
];