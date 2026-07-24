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
            'name' => 'Webinar Attended Nurture',
            'description' => 'Default one-step marketing follow-up for contacts who attended a webinar.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'variant_strategy' => 'dependency_aware',
            'source_version' => 3,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'sms_preferred_email_fallback',
                'notes' => 'Transactional replay delivery belongs to Webinars. This campaign provides one later marketing follow-up, prefers SMS when available, and sends email only after the same enrollment\'s SMS is sent or when SMS is unavailable for the client.',
            ],
            'steps' => [
                [
                    'name' => 'Attended webinar follow-up',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
                        ],
                    ],
                    'variants' => [
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                        ],
                        'email' => [
                            'name' => 'Email fallback',
                            'channel' => 'email',
                            'dependency_rules' => [
                                'requires_variant_states' => [
                                    'sms' => ['sent', 'unavailable'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        'webinar_missed_nurture' => [
            'name' => 'Webinar Missed Nurture',
            'description' => 'Default one-step marketing follow-up for contacts who registered for a webinar but missed it.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'variant_strategy' => 'dependency_aware',
            'source_version' => 3,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'sms_preferred_email_fallback',
                'notes' => 'Transactional replay delivery belongs to Webinars. This campaign provides one later marketing follow-up, prefers SMS when available, and sends email only after the same enrollment\'s SMS is sent or when SMS is unavailable for the client.',
            ],
            'steps' => [
                [
                    'name' => 'Missed webinar follow-up',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
                        ],
                    ],
                    'variants' => [
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                        ],
                        'email' => [
                            'name' => 'Email fallback',
                            'channel' => 'email',
                            'dependency_rules' => [
                                'requires_variant_states' => [
                                    'sms' => ['sent', 'unavailable'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];