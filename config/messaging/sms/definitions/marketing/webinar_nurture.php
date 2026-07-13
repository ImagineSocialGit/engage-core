<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [
    'opt_ins' => [
        [
            'key' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'payload_class' => SmsPayload::class,
            'queue' => 'opt_in_messages',
            'payload' => [
                'message' => 'Thanks for subscribing to webinar follow-up texts. Reply HELP for help. Msg&data rates may apply. Reply STOP to opt out.',
            ],
        ],
    ],
];
