<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [
    'campaigns' => [
        'webinar_attended_nurture' => [
            'steps' => [
                1 => [
                    'variants' => [
                        'sms' => [
                            'dispatch_key' => 'campaign_step_due',
                            'message_type' => 'campaign_step',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'payload_class' => SmsPayload::class,
                            'queue' => 'campaigns',
                            'payload' => [
                                'message' => 'Hi {first_name}, thanks again for joining the webinar. If you still have questions or want help figuring out your next step, just reply to this message.',
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
                            'message_type' => 'campaign_step',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'payload_class' => SmsPayload::class,
                            'queue' => 'campaigns',
                            'payload' => [
                                'message' => 'Hi {first_name}, sorry we missed you at the webinar. If you still have questions or want help figuring out your next step, just reply to this message.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];