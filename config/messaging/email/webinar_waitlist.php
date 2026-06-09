<?php

use App\Messaging\Payloads\Webinars\Email\WebinarWaitlistScheduledEmailPayload;

return [

    'scheduled' => [
        'enabled' => true,
        'scope' => 'webinar_waitlist',
        'purpose' => 'marketing',
        'message_type' => 'webinar_waitlist_scheduled',
        'payload_class' => WebinarWaitlistScheduledEmailPayload::class,
        'queue' => 'notifications',
    ],

    'opt_in' => [
        'enabled' => true,
        'scope' => 'webinar_waitlist',
        'purpose' => 'marketing',
        'message_type' => 'webinar_waitlist_opt_in',
        'payload_class' => App\Messaging\Payloads\Marketing\Email\MarketingEmailPayload::class,
        'queue' => 'opt_in_messages',

        'payload' => [
            'subject' => 'You’re on the webinar waitlist',
            'body' => 'Thanks for joining the webinar waitlist. We’ll let you know when a new session is available.',
        ],
    ],

];