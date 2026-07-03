<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    'alerts' => [
        [
            'dispatch_key' => 'webinar_added',
            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',

            'conditions' => [
                [
                    'field' => 'webinar.registration_url',
                    'operator' => 'filled',
                ],
            ],

            'payload' => [
                'subject' => 'New webinar scheduled: {webinar_title}',
                'body' => 'A new webinar session is available. Register here: {webinar_registration_url}',
                'cta' => [
                    'label' => 'Register Now',
                    'url' => '{webinar_registration_url}',
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
                'subject' => 'You’re on the webinar waitlist',
                'body' => 'Thanks for subscribing to webinar updates. We’ll let you know when a new session is available.',
            ],
        ],
    ],

];
