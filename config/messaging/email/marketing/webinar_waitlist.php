<?php

use App\Messaging\Payloads\EmailPayload;

return [

    'webinar_waitlist_scheduled' => [
        'dispatch_key' => 'webinar_added',
        'timing' => 'immediate',
        'payload_class' => EmailPayload::class,
        'queue' => 'notifications',

        'payload' => [
            'subject' => 'New webinar scheduled: {webinar_title}',
            'view' => 'emails.webinars.waitlist-scheduled',
        ],
    ],

    'webinar_waitlist_opt_in' => [
        'dispatch_key' => 'consent_granted',
        'timing' => 'immediate',
        'payload_class' => EmailPayload::class,
        'queue' => 'opt_in_messages',

        'payload' => [
            'subject' => 'You’re on the webinar waitlist',
            'body' => 'Thanks for joining the webinar waitlist. We’ll let you know when a new session is available.',
        ],
    ],

];