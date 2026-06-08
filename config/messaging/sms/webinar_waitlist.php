<?php

use App\Messaging\Payloads\Webinars\Sms\WebinarWaitlistScheduledSmsPayload;

return [

    'scheduled' => [
        'enabled' => true,
        'scope' => 'webinar_waitlist',
        'purpose' => 'marketing',
        'message_type' => 'webinar_waitlist_scheduled',
        'payload_class' => WebinarWaitlistScheduledSmsPayload::class,
        'queue' => 'notifications',
    ],

    'opt_in' => [
        'enabled' => true,
        'scope' => 'webinar_waitlist',
        'purpose' => 'marketing',
        'message_type' => 'webinar_waitlist_opt_in',
        'payload_class' => App\Messaging\Payloads\Marketing\Sms\MarketingSmsPayload::class,
        'queue' => 'confirmation_messages',

        'payload' => [
            'message' => 'Thanks for joining the webinar waitlist. We’ll let you know when a new session is available. Reply STOP to opt out.',
        ],
    ],

];