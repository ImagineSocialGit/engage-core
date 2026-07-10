<?php

return [
    'groups' => [
        'webinar_default' => [
            'webinar_attended_nurture',
            'webinar_missed_nurture',
        ],
    ],

    'definitions' => [
        'webinar_attended_nurture' => [
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'description' => 'Default marketing nurture sequence for contacts who attended a webinar and should receive follow-up after the transactional replay/thank-you message.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => '3',
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_primary_sms_supplemental',
                'notes' => 'Transactional thank-you/replay delivery belongs to Webinars. This campaign handles later marketing nurture only.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Attended webinar next step',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
                        ],
                        [
                            'key' => 'sms',
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.sms.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Attended webinar common questions',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.2.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 4,
                    'name' => 'Attended webinar down-payment education',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 4,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.4.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 6,
                    'name' => 'Attended webinar real-deal story',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 5,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.6.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 8,
                    'name' => 'Attended webinar timing follow-up',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 5,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.8.variants.email',
                        ],
                    ],
                ],
            ],
        ],

        'webinar_missed_nurture' => [
            'key' => 'webinar_missed_nurture',
            'name' => 'Webinar Missed Nurture',
            'description' => 'Default marketing nurture sequence for contacts who registered for a webinar but missed it.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => '2',
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_primary_sms_supplemental',
                'notes' => 'Transactional replay delivery belongs to Webinars. This campaign handles later marketing nurture only.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Missed webinar next step',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.1.variants.email',
                        ],
                        [
                            'key' => 'sms',
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.sms.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.1.variants.sms',
                        ],
                    ],
                ],
                [
                    'step_number' => 2,
                    'name' => 'Missed webinar pre-approval education',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.2.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Missed webinar strategy follow-up',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.3.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 4,
                    'name' => 'Missed webinar deal story',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.4.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 5,
                    'name' => 'Missed webinar biggest mistake',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.5.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 6,
                    'name' => 'Missed webinar replay last chance',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.6.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 7,
                    'name' => 'Missed webinar next class invite',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.7.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 8,
                    'name' => 'Missed webinar attendance check-in',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.8.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 9,
                    'name' => 'Missed webinar timing education',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.9.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 10,
                    'name' => 'Missed webinar credit education',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.10.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 11,
                    'name' => 'Missed webinar down-payment education',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.11.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 12,
                    'name' => 'Missed webinar pre-approval warning',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.12.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 13,
                    'name' => 'Missed webinar buyer story',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.13.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 14,
                    'name' => 'Missed webinar mid-process warning',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.14.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 15,
                    'name' => 'Missed webinar renting check-in',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.15.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 16,
                    'name' => 'Missed webinar winning-offer education',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.16.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 17,
                    'name' => 'Missed webinar mortgage-cost education',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.17.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 18,
                    'name' => 'Missed webinar buy-before-sell education',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.18.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 19,
                    'name' => 'Missed webinar final check-in',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '3',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'meta' => [
                        'type' => 'message',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.19.variants.email',
                        ],
                    ],
                ],
            ],
        ],
    ],
];