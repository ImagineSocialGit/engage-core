<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    'alerts' => [
        [
            'key' => 'alert',
            'dispatch_key' => 'webinar_added',
            'message_type' => 'alert',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',

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
            'key' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'payload_class' => EmailPayload::class,
            'queue' => 'opt_in_messages',

            'payload' => [
                'subject' => 'You’re on the webinar waitlist',
                'body' => 'Thanks for subscribing to webinar updates. We’ll let you know when a new session is available.',
            ],
        ],
    ],

];
