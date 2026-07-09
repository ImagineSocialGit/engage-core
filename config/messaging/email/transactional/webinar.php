<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Transactional Webinar Email Templates
    |--------------------------------------------------------------------------
    |
    | These messages belong to the Webinar process.
    |
    | They are not Campaigns.
    | They are not marketing nurture.
    |
    | Purpose/scope:
    |
    | transactional:webinar
    |
    | Keep this default copy vertical-neutral.
    |
    */

    'confirmations' => [
        [
            'key' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_type' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'conditions' => [
                [
                    'field' => 'webinar.starts_at',
                    'operator' => 'at_least_minutes_from_now',
                    'value' => 30,
                ],
            ],

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',

            'schedule' => [
                'type' => 'delay',
                'minutes' => 15,
            ],

            'payload' => [
                'subject' => 'You’re registered: {webinar_title}',
                'body' => 'Thanks for registering for {webinar_title}! We’ll send reminders and your join link before the webinar starts.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
                'secondary_link' => [
                    'label' => 'Need to cancel your registration?',
                    'url' => '{cancel_registration_url}',
                ],
            ],
        ],
    ],

    'opt_ins' => [
        [
            'key' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'opt_in_messages',

            'payload' => [
                'subject' => 'You’re subscribed to webinar emails',
                'body' => 'Thanks for subscribing to receive webinar-related emails. You can opt out of these messages using the link in any webinar email.',
            ],
        ],
    ],

    'reminders' => [
        [
            'key' => 'reminder_10_day',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -14400,
            ],

            'payload' => [
                'subject' => 'Your webinar is coming up in 10 days',
                'body' => 'Hi {first_name}, your webinar is coming up in 10 days. We’ll send your join link again as the event gets closer.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],

        [
            'key' => 'reminder_1_week',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -10080,
            ],

            'payload' => [
                'subject' => 'Your webinar is one week away',
                'body' => 'Hi {first_name}, your webinar is one week away. Save the date and use the link below when it is time to join.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],

        [
            'key' => 'reminder_1_day',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -1440,
            ],

            'payload' => [
                'subject' => 'Your webinar is tomorrow',
                'body' => 'Hi {first_name}, your webinar is tomorrow. Use the link below when it is time to join.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],

        [
            'key' => 'reminder_30_minute',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -30,
            ],

            'payload' => [
                'subject' => 'Your webinar starts in 30 minutes',
                'body' => 'Hi {first_name}, your webinar starts in 30 minutes. Use the link below to join.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],

        [
            'key' => 'reminder_10_minute',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -10,
            ],

            'payload' => [
                'subject' => 'Your webinar starts in 10 minutes',
                'body' => 'Hi {first_name}, your webinar starts in 10 minutes. Use the link below to join.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],

        [
            'key' => 'reminder_live',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'skip_when_join_clicked' => true,
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => 5,
            ],

            'payload' => [
                'subject' => 'Your webinar is live',
                'body' => 'Hi {first_name}, {webinar_title} is live. Use the link below to join now.',
                'cta' => [
                    'label' => 'Join Now',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
    ],

    'post_attended' => [
        [
            'key' => 'post_attended',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_attended',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'conditions' => [
                [
                    'field' => 'webinar_registration.attended_at',
                    'operator' => 'filled',
                ],
            ],

            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'subject' => 'Thanks for joining: {webinar_title}',
                'body' => 'Hi {first_name}, thanks for joining {webinar_title}. If you want to review anything we covered, you can use the replay link below.',
                'cta' => [
                    'label' => 'Watch Replay',
                    'url' => '{webinar_playback_url}',
                ],
            ],
        ],
    ],

    'post_missed' => [
        [
            'key' => 'post_missed',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_missed',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'conditions' => [
                [
                    'field' => 'webinar_registration.attended_at',
                    'operator' => 'blank',
                ],
            ],

            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'subject' => 'Sorry we missed you: {webinar_title}',
                'body' => 'Hi {first_name}, sorry we missed you at {webinar_title}. You can still watch the replay using the link below.',
                'cta' => [
                    'label' => 'Watch Replay',
                    'url' => '{webinar_playback_url}',
                ],
            ],
        ],
    ],

];


