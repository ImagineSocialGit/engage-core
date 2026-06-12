<?php

use App\Messaging\Payloads\SmsPayload;

return [

    'opt_in' => [
        'dispatch_key' => 'consent_granted',
        'timing' => 'immediate',
        'payload_class' => SmsPayload::class,
        'queue' => 'opt_in_messages',

        'payload' => [
            'message' => 'Thanks for subscribing to receive marketing messages! Reply HELP for help. Message frequency may vary. Msg&data rates may apply. Reply STOP to opt out.',
        ],
    ],

    'message' => [
        'timing' => 'webinar_ended',
        'payload_class' => SmsPayload::class,
        'queue' => 'marketing',

        'schedule' => [
            'type' => 'delay',
            'minutes' => 0,
        ],

        'payload' => [],
    ],

];