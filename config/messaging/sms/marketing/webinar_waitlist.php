<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [

    'alerts' => [
        [
            'key' => 'alert',
            'dispatch_key' => 'webinar_added',
            'message_type' => 'alert',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'timing' => 'immediate',
            'payload_class' => SmsPayload::class,
            'queue' => 'notifications',

            'conditions' => [
                [
                    'field' => 'webinar.registration_url',
                    'operator' => 'filled',
                ],
            ],

            'payload' => [
                'message' => 'A new webinar has been scheduled for {webinar_title}. Register here: {webinar_registration_url}',
            ],
        ],
    ],

    'opt_ins' => [
        [
            'key' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'timing' => 'immediate',
            'payload_class' => SmsPayload::class,
            'queue' => 'opt_in_messages',

            'payload' => [
                'message' => 'Thanks for joining the webinar waitlist. We’ll let you know when a new session is available. Reply STOP to opt out.',
            ],
        ],
    ],

];


