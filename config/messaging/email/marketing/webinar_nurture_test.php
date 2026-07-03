<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Disposable Marketing Webinar Nurture Test Email Templates
    |--------------------------------------------------------------------------
    |
    | Purpose/scope:
    |
    | marketing:webinar_nurture_test
    |
    | This file is intentionally temporary for short-interval smoke testing.
    | Delete it after the smoke test when the test campaigns/routes are removed.
    |
    */

    'campaigns' => [
        'webinar_attended_nurture_email_test' => [
            'steps' => [
                1 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Smoke test email 1: thanks for attending',
                        'body' => 'Hi {first_name}, this is smoke test email 1 after attending the webinar.',
                    ],
                ],

                2 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Smoke test email 2: quick follow-up',
                        'body' => 'Hi {first_name}, this is smoke test email 2. Everything is still moving through the test nurture path.',
                    ],
                ],

                3 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Smoke test email 3: final expected send',
                        'body' => 'Hi {first_name}, this is smoke test email 3. Change the contact status to Not Interested before the next step to confirm step 4 skips.',
                    ],
                ],

                4 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'conditions' => [
                        [
                            'field' => 'contact.status',
                            'operator' => 'not',
                            'value' => 'not_interested',
                        ],
                    ],

                    'payload' => [
                        'subject' => 'Smoke test email 4: should skip',
                        'body' => 'Hi {first_name}, this is smoke test email 4. If the status was changed to Not Interested, this message should be skipped.',
                    ],
                ],
            ],
        ],
    ],

];
