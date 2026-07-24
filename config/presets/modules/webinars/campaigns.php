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
            'name' => 'Slam Dunk Webinar Attended Nurture',
            'description' => 'Initial 30-day marketing nurture for webinar attendees. Uses general homebuyer education and reply-based prompts so it does not assume an application-state event exists.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'inactive',
            'variant_strategy' => 'send_all_eligible',
            'source_version' => 1,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_primary_sms_supplemental',
                'client' => 'slam-dunk-crm',
                'initial_rollout_window' => '30_days',
            ],
            'steps' => [
                [
                    'name' => 'Most buyers do this wrong',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'The payment problem',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.2.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Buying timeline check-in',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.3.variants.sms',
                        ],
                    ],
                ],
                [
                    'name' => 'No, you do not need 20% down',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 4,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.4.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Homebuying timeline check-in',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.5.variants.sms',
                        ],
                    ],
                ],
                [
                    'name' => 'Real deal story',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 5,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.6.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Would you be ready tomorrow?',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.7.variants.sms',
                        ],
                    ],
                ],
                [
                    'name' => 'Waiting might cost more',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 5,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.8.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Final month-one check-in',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.9.variants.sms',
                        ],
                    ],
                ],
            ],
        ],
        'webinar_missed_nurture' => [
            'name' => 'Slam Dunk Webinar Missed Nurture',
            'description' => 'Initial 30-day marketing nurture for contacts who registered for a webinar but missed it.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'inactive',
            'variant_strategy' => 'send_all_eligible',
            'source_version' => 1,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_primary_sms_supplemental',
                'client' => 'slam-dunk-crm',
                'initial_rollout_window' => '30_days',
            ],
            'steps' => [
                [
                    'name' => 'Missed webinar follow-up',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.1.variants.email',
                        ],
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.1.variants.sms',
                        ],
                    ],
                ],
                [
                    'name' => 'Why buyers get denied',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.2.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Let me be blunt',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.3.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'This deal almost blew up',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.4.variants.email',
                        ],
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.4.variants.sms',
                        ],
                    ],
                ],
                [
                    'name' => 'Biggest mistake buyers make',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.5.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Replay last chance',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.6.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Next class is open',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.7.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'What stopped you from attending?',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.8.variants.email',
                        ],
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.8.variants.sms',
                        ],
                    ],
                ],
                [
                    'name' => 'Most people wait too long',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.9.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'You do not need perfect credit',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.10.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'You do not need 20% down',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.11.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Instant pre-approvals are not real approvals',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.12.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'They almost lost the house',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.13.variants.email',
                        ],
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.13.variants.sms',
                        ],
                    ],
                ],
                [
                    'name' => 'Do not do this mid-process',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.14.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Still renting?',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.15.variants.email',
                        ],
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.15.variants.sms',
                        ],
                    ],
                ],
                [
                    'name' => 'What wins deals',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.16.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Small mortgage decisions have big consequences',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.17.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Buy before you sell',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.18.variants.email',
                        ],
                    ],
                ],
                [
                    'name' => 'Be honest',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.19.variants.email',
                        ],
                    ],
                ],
            ],
        ],
    ],
];