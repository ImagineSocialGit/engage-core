<?php

use App\Messaging\Payloads\EmailPayload;

return [

    'alerts' => [
        [
            'dispatch_key' => 'webinar_added',
            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',

            'payload' => [
                'subject' => 'New webinar scheduled: {webinar_title}',
                'body' => '',
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
                'body' => 'Thanks for subscribing to get updates for {webinar_series} availability. We’ll let you know when a new session is available.',
            ],
        ],
    ],

];