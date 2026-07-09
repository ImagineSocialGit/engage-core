
<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [
    'opt_ins' => [
        [
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'timing' => 'immediate',
            'payload_class' => SmsPayload::class,
            'queue' => 'opt_in_messages',
            'payload' => [
                'message' => 'Thanks for subscribing to webinar follow-up texts. Reply HELP for help. Msg&data rates may apply. Reply STOP to opt out.',
            ],
        ],
    ],

    'campaigns' => [
        'webinar_attended_nurture' => [
            'steps' => [
                1 => [
                    'variants' => [
                        'sms' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => SmsPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'message' => 'Thanks for joining the webinar, {first_name}. Reply with your biggest question and we’ll help with the next step. Reply STOP to opt out.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'webinar_missed_nurture' => [
            'steps' => [
                1 => [
                    'variants' => [
                        'sms' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => SmsPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'message' => 'Sorry we missed you at the webinar, {first_name}. Reply with your biggest question and we’ll help. Reply STOP to opt out.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
