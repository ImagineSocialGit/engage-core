<?php

use App\Messaging\Payloads\EmailPayload;

return [

    'confirmation' => [
        'dispatch_key' => 'registration_created',
        'timing' => 'immediate',
        'payload_class' => EmailPayload::class,
        'queue' => 'confirmation_messages',

        'payload' => [
            'subject' => 'You’re registered: {webinar_title}',
            'body' => 'Thanks for registering for {webinar_title}! We\'ll be sending you reminders and your join link soon!'
        ],
    ],

    'opt_in' => [
        'dispatch_key' => 'consent_granted',
        'timing' => 'immediate',
        'payload_class' => EmailPayload::class,
        'queue' => 'opt_in_messages',

        'payload' => [
            'subject' => 'You’re subscribed to webinar emails',
            'body' => 'Thanks for subscribing to receive webinar-related emails. You can opt out of these messages using the link in any webinar email.',
        ],
    ],

    'reminder' => [
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
        ],
    ],

    'reminder' => [
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
        ],
    ],

    'reminder' => [
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
        ],
    ],

    'reminder' => [
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
        ],
    ],

    'reminder' => [
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
        ],
    ],

    'reminder' => [
        'dispatch_key' => 'registration_created',
        'timing' => 'scheduled',
        'payload_class' => EmailPayload::class,
        'queue' => 'reminders',

        'schedule' => [
            'type' => 'anchored',
            'minutes' => 0,
        ],

        'payload' => [
            'message' => '{webinar_title} is live! Join here: {webinar_join_url}',
        ],
    ],

    'post_attended' => [
        'dispatch_key' => 'webinar_ended',
        'conditions' => [
            'webinar_registration.attended_truthy' => true,
        ],
        'timing' => 'immediate',
        'payload_class' => EmailPayload::class,
        'queue' => 'notifications',

        'payload' => [
            'subject' => 'Thanks for joining: {webinar_title}',
        ],
    ],

    'post_missed' => [
        'dispatch_key' => 'webinar_ended',
        'conditions' => [
            'webinar_registration.attended_falsy' => true,
        ],
        'timing' => 'immediate',
        'payload_class' => EmailPayload::class,
        'queue' => 'notifications',

        'payload' => [
            'subject' => 'Sorry we missed you: {webinar_title}',
        ],
    ],

];