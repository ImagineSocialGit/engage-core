<?php

use App\Messaging\Payloads\SmsPayload;

return [

    'scheduled' => [
        'dispatch_key' => 'webinar_added',
        'timing' => 'immediate',
        'payload_class' => SmsPayload::class,
        'queue' => 'notifications',

        'payload' => [
            'message' => 'A new webinar has been scheduled for {webinar_title}. Register here: {registration_url}',
        ],
    ],

    'opt_in' => [
        'dispatch_key' => 'consent_granted',
        'timing' => 'immediate',
        'payload_class' => SmsPayload::class,
        'queue' => 'opt_in_messages',

        'payload' => [
            'message' => 'Thanks for joining the webinar waitlist. We’ll let you know when a new session is available. Reply STOP to opt out.',
        ],
    ],

];