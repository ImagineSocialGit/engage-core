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
            'source_version' => '2',
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
                    'source_version' => '2',
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
                    'source_version' => '2',
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
                    'step_number' => 3,
                    'name' => 'Attended webinar long-term handoff',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '2',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
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
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.3.variants.email',
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
            'source_version' => '1',
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
                    'source_version' => '1',
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
                    'name' => 'Missed webinar next class invite',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '1',
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
                            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.2.variants.email',
                        ],
                    ],
                ],
                [
                    'step_number' => 3,
                    'name' => 'Missed webinar long-term handoff',
                    'variant_strategy' => 'send_all_eligible',
                    'is_active' => true,
                    'source_version' => '1',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
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
            ],
        ],
    ],
];

