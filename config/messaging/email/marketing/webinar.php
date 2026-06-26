<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    'opt_ins' => [
        [
            'dispatch_key' => 'consent_granted',
            'timing' => 'immediate',
            'payload_class' => EmailPayload::class,
            'queue' => 'opt_in_messages',

            'payload' => [
                'subject' => 'You’re subscribed',
                'body' => 'Thanks for subscribing to receive marketing messages! You can unsubscribe at any time.',
            ],
        ],
    ],

    'general_messages' => [
        [
            'dispatch_key' => 'webinar_ended',
            'campaign_key' => 'webinar_attended',
            'step' => 1,
            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',

            'schedule' => [
                'type' => 'delay',
                'minutes' => 720,
            ],

            'payload' => [
                'subject' => 'Just checking in!',
                'body' =>
                    'Hey {first_name},
                
                Just wanted to see if you\'ve put some thought into what I discussed during my webinar!
                
                Let me know if you have any new questions, or think you\'re ready to get moving!'
            ],
        ],
    ],

];