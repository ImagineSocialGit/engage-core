
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign Presets
    |--------------------------------------------------------------------------
    |
    | Campaign presets define journeys.
    |
    | Campaigns own:
    | - campaign identity
    | - step order
    | - step timing
    | - message template references
    |
    | Campaigns do not own reusable message copy.
    | Campaigns do not define or override payloads.
    |
    | Campaign message copy is resolved by:
    |
    | messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}
    |
    */

    'groups' => [
        'webinar_default' => [
            'webinar_attended_nurture',
            'webinar_missed_nurture',
        ],

        'mortgage_default' => [
            'webinar_attended_nurture',
            'webinar_missed_nurture',
            'mortgage_homebuyer_nurture',
            'webinar_attended_nurture_email_test',
            'webinar_attended_nurture_sms_test',
        ],
    ],

    'definitions' => [

        'webinar_attended_nurture' => [
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'description' => 'Default marketing nurture sequence for leads who attended a webinar but have not yet taken the next step.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 3,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_primary_sms_supplemental',
                'notes' => 'Campaign steps are resolved by campaign key and step number. Email carries the core narrative. SMS can be added later as supplemental steps.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Attended thank-you and next step',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Common next-step questions',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Long-term nurture handoff',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
            ],
        ],

        'webinar_missed_nurture' => [
            'key' => 'webinar_missed_nurture',
            'name' => 'Webinar Missed Nurture',
            'description' => 'Default marketing nurture sequence for leads who registered for a webinar but missed it.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 3,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_primary_sms_supplemental',
                'notes' => 'Transactional replay delivery belongs to Webinars. This campaign handles later marketing nurture only.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Missed webinar next step',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Missed webinar next class invite',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Missed webinar long-term handoff',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
            ],
        ],

        'mortgage_homebuyer_nurture' => [
            'key' => 'mortgage_homebuyer_nurture',
            'name' => 'Mortgage Homebuyer Nurture',
            'description' => 'Default long-term mortgage homebuyer education sequence for leads who are not ready immediately.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 3,
            'meta' => [
                'domain' => 'mortgage',
                'strategy' => 'email_primary_sms_supplemental',
                'notes' => 'Mortgage-specific nurture. Only sync this through mortgage preset groups.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Preparation basics',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 14,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'mortgage_homebuyer_nurture',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Timeline education',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 45,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'mortgage_homebuyer_nurture',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Soft reactivation',
                    'dispatch_key' => 'campaign_step_due',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 90,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'mortgage_homebuyer_nurture',
                        ],
                    ],
                ],
            ],
        ],


        'webinar_attended_nurture_email_test' => [
            'key' => 'webinar_attended_nurture_email_test',
            'name' => 'Webinar Attended Email Nurture Smoke Test',
            'description' => 'Disposable smoke-test attended webinar EMAIL nurture sequence. Delete after smoke testing.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture_test',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 'smoke_test_2026_07',
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'smoke_test_short_interval',
                'temporary' => true,
                'notes' => 'Four short-interval smoke steps. Step 4 should be skipped if the contact status changes to not_interested before send time.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Smoke attended email step 1',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture_test',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'minutes' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'temporary' => true,
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture_test',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Smoke attended email step 2',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture_test',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'minutes' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'temporary' => true,
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture_test',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Smoke attended email step 3',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture_test',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'minutes' => 3,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'temporary' => true,
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture_test',
                        ],
                    ],
                ],
                [
                    'step_number' => 4,
                    'name' => 'Smoke attended email step 4 should skip after status change',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture_test',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'minutes' => 3,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'temporary' => true,
                        'expected_smoke_result' => 'skip_when_contact_status_is_not_interested',
                        'message' => [
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture_test',
                        ],
                    ],
                ],
            ],
        ],


        'webinar_attended_nurture_sms_test' => [
            'key' => 'webinar_attended_nurture_sms_test',
            'name' => 'Webinar Attended SMS Nurture Smoke Test',
            'description' => 'Disposable smoke-test attended webinar SMS nurture sequence. Delete after smoke testing.',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture_test',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 'smoke_test_2026_07',
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'smoke_test_short_interval',
                'temporary' => true,
                'notes' => 'Four short-interval smoke steps. Step 4 should be skipped if the contact status changes to not_interested before send time.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Smoke attended sms step 1',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'sms',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture_test',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'minutes' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'temporary' => true,
                        'message' => [
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture_test',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Smoke attended sms step 2',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'sms',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture_test',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'minutes' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'temporary' => true,
                        'message' => [
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture_test',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Smoke attended sms step 3',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'sms',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture_test',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'minutes' => 3,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'temporary' => true,
                        'message' => [
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture_test',
                        ],
                    ],
                ],
                [
                    'step_number' => 4,
                    'name' => 'Smoke attended sms step 4 should skip after status change',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'sms',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture_test',
                    'is_active' => true,
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'minutes' => 3,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                        'temporary' => true,
                        'expected_smoke_result' => 'skip_when_contact_status_is_not_interested',
                        'message' => [
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture_test',
                        ],
                    ],
                ],
            ],
        ],

    ],

];
