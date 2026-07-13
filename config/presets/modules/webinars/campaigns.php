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
            'is_active' => true,
            'source_version' => 'core_generic_2026_07_1',
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_only',
                'notes' => 'Transactional replay delivery belongs to Webinars. This campaign provides a simple later marketing follow-up.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Attended webinar follow-up',
                    'variant_strategy' => 'first_available',
                    'is_active' => true,
                    'source_version' => 'core_generic_2026_07_1',
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
            'is_active' => true,
            'source_version' => 'core_generic_2026_07_1',
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_only',
                'notes' => 'Transactional replay delivery belongs to Webinars. This campaign provides a simple later marketing follow-up.',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Missed webinar follow-up',
                    'variant_strategy' => 'first_available',
                    'is_active' => true,
                    'source_version' => 'core_generic_2026_07_1',
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
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.1.variants.email',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
