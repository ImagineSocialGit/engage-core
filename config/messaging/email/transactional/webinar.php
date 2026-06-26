<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    'confirmations' => [
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',

            'schedule' => [
                'type' => 'delay',
                'minutes' => 15,
            ],

            'payload' => [
                'subject' => 'You’re registered: {webinar_title}',
                'body' => 'Thanks for registering for {webinar_title}! We\'ll be sending you reminders and your join link soon!',

                'secondary_link' => [
                    'label' => 'Need to cancel your registration?',
                    'url' => '{cancel_registration_url}',
                ],
            ],
        ],
    ],

    'opt_ins' => [
        [
            'dispatch_key' => 'consent_granted',
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
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -14400,
            ],

            'payload' => [
                'subject' => 'Your webinar is coming up in 10 days',
                'body' => 'Your webinar is coming up. Use the button below to join when it’s time.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -10080,
            ],

            'payload' => [
                'subject' => 'Your webinar is one week away',
                'body' => 'Your webinar is coming up. Use the button below to join when it’s time.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -1440,
            ],

            'payload' => [
                'subject' => 'Your webinar is tomorrow',
                'body' => 'Your webinar is tomorrow. Use the button below to join when it’s time.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -30,
            ],

            'payload' => [
                'subject' => 'Your webinar starts in 30 minutes',
                'body' => 'Your webinar starts in 30 minutes. Use the button below to join.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -10,
            ],

            'payload' => [
                'subject' => 'Your webinar starts in 10 minutes',
                'body' => 'Your webinar starts in 10 minutes. Use the button below to join.',
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
        [
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => 0,
            ],

            'payload' => [
                'subject' => 'Your webinar is live!',
                'body' => '{webinar_title} is live. Use the button below to join now.',
                'cta' => [
                    'label' => 'Join Now',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
    ],

    'post_attended' => [
        [
            'dispatch_key' => 'webinar_ended',
            'conditions' => [
                'field' => 'webinar_registration.attended_at',
                'operator' => 'filled',
            ],
            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'subject' => 'Thanks for joining: {webinar_title}',
                'body' => 'I\'ll be sending out replay access shortly!',
            ],
        ],
    ],

    'post_missed' => [
        [
            'dispatch_key' => 'webinar_ended',
            'conditions' => [
                'field' => 'webinar_registration.attended_at',
                'operator' => 'blank',
            ],
            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'subject' => 'Sorry we missed you: {webinar_title}',
                'body' => 'I\'ll be sending out replay access shortly!',
            ],
        ],
    ],

];