<?php

use App\Messaging\Payloads\Marketing\Email\MarketingEmailPayload;

return [

    'opt_in' => [
        'enabled' => true,
        'scope' => 'general_drip',
        'purpose' => 'marketing',
        'message_type' => 'general_drip_opt_in',
        'payload_class' => MarketingEmailPayload::class,
        'queue' => 'confirmation_messages',

        'payload' => [
            'subject' => 'You’re subscribed',
            'body' => 'Thanks for subscribing to receive updates. You can unsubscribe at any time.',
        ],
    ],

];