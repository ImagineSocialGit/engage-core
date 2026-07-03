<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Disposable Marketing Webinar Nurture Test SMS Templates
    |--------------------------------------------------------------------------
    |
    | Purpose/scope:
    |
    | marketing:webinar_nurture_test
    |
    | This file is intentionally temporary for short-interval smoke testing.
    |
    */

    'campaigns' => [
        'webinar_attended_nurture_sms_test' => [
            'steps' => [
                1 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'message' => 'Smoke SMS 1: thanks for attending the webinar.',
                    ],
                ],

                2 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'message' => 'Smoke SMS 2: quick follow-up. The test nurture path is still moving.',
                    ],
                ],

                3 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'message' => 'Smoke SMS 3: final expected send. Change status to Not Interested before step 4.',
                    ],
                ],

                4 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'marketing',

                    'conditions' => [
                        [
                            'field' => 'contact.status',
                            'operator' => 'not',
                            'value' => 'not_interested',
                        ],
                    ],

                    'payload' => [
                        'message' => 'Smoke SMS 4: this should skip if status is Not Interested.',
                    ],
                ],
            ],
        ],
    ],

];
