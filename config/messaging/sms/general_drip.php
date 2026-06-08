<?php

use App\Messaging\Payloads\Marketing\Sms\MarketingSmsPayload;

return [

    'opt_in' => [
        'enabled' => true,
        'scope' => 'general_drip',
        'purpose' => 'marketing',
        'message_type' => 'general_drip_opt_in',
        'payload_class' => MarketingSmsPayload::class,
        'queue' => 'confirmation_messages',

        'payload' => [
            'message' => 'Thanks for subscribing to receive marketing messages! Reply HELP for help. Message frequency may vary. Msg&data rates may apply. Reply STOP to opt out.',
        ],
    ],

    'drip' => [
        'enabled' => true,
        'scope' => 'general_drip',
        'purpose' => 'marketing',
        'message_type' => 'general_drip_message',
        'payload_class' => MarketingSmsPayload::class,
        'queue' => 'notifications',

        'schedule' => [
            'delay_minutes' => 0,
        ],
    ],

];