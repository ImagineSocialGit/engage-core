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
            'description' => 'Default one-step marketing follow-up for contacts who attended a webinar.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'source_version' => 3,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'sms_preferred_email_fallback',
                'notes' => 'Transactional replay delivery belongs to Webinars. This campaign provides one later marketing follow-up, prefers SMS when available, and sends email only after the same enrollment\'s SMS is sent or when SMS is unavailable for the client.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Attended webinar follow-up',
                    'variant_strategy' => 'dependency_aware',
                    'is_active' => true,
                    'source_version' => 3,
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
                            'key' => 'sms',
                            'name' => 'SMS follow-up',
                            'sort_order' => 10,
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
                        ],
                        [
                            'key' => 'email',
                            'name' => 'Email fallback',
                            'sort_order' => 20,
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'dependency_rules' => [
                                'requires_variant_states' => [
                                    'sms' => ['sent', 'unavailable'],
                                ],
                            ],
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
                        ],
                    ],
                ],
            ],
        ],

        'webinar_missed_nurture' => [
            'key' => 'webinar_missed_nurture',
            'name' => 'Webinar Missed Nurture',
            'description' => 'Default one-step marketing follow-up for contacts who registered for a webinar but missed it.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'source_version' => 3,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'sms_preferred_email_fallback',
                'notes' => 'Transactional replay delivery belongs to Webinars. This campaign provides one later marketing follow-up, prefers SMS when available, and sends email only after the same enrollment\'s SMS is sent or when SMS is unavailable for the client.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Missed webinar follow-up',
                    'variant_strategy' => 'dependency_aware',
                    'is_active' => true,
                    'source_version' => 3,
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
                            'key' => 'sms',
                            'name' => 'SMS follow-up',
                            'sort_order' => 10,
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.1.variants.sms',
                        ],
                        [
                            'key' => 'email',
                            'name' => 'Email fallback',
                            'sort_order' => 20,
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dispatch_key' => 'campaign_step_due',
                            'is_active' => true,
                            'dependency_rules' => [
                                'requires_variant_states' => [
                                    'sms' => ['sent', 'unavailable'],
                                ],
                            ],
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.1.variants.email',
                        ],
                    ],
                ],
            ],
        ],
    ],
];