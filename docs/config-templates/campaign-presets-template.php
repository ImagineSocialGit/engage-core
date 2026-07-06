<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign Presets Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/presets/campaigns.php
    | client/{client-key}/config/presets/campaigns.php, if client override exists
    |
    | Campaign = enrolled multi-step journey.
    |
    | Campaign presets own:
    | - campaign identity
    | - step order
    | - step timing
    | - message template references
    |
    | Messaging configs own:
    | - reusable message copy
    | - subject/body/CTA payloads
    | - payload_class
    | - delivery queue
    |
    | Campaign presets must not own reusable subject/body copy.
    | Campaign presets must not define or override payloads.
    |
    | Campaign preset steps reference Messaging templates with first-class:
    | - channel
    | - purpose
    | - scope
    |
    | Do not use meta.message for new Campaign preset step message references.
    |
    | Campaign message templates resolve by:
    |
    | messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}
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
        ],
    ],

    'definitions' => [

        'webinar_attended_nurture' => [
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'description' => 'Follow-up sequence for leads who attended a webinar.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 1,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_primary_sms_supplemental',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Attended thank-you and next step',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture',
                    'is_active' => true,

                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],

                    'meta' => [
                        'type' => 'message',
                    ],
                ],

                [
                    'step_number' => 2,
                    'name' => 'Common next-step questions',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture',
                    'is_active' => true,

                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],

                    'meta' => [
                        'type' => 'message',
                    ],
                ],
            ],
        ],

        'webinar_missed_nurture' => [
            'key' => 'webinar_missed_nurture',
            'name' => 'Webinar Missed Nurture',
            'description' => 'Follow-up sequence for leads who registered for a webinar but missed it.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 1,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'email_primary_sms_supplemental',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Missed webinar next step',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture',
                    'is_active' => true,

                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],

                    'meta' => [
                        'type' => 'message',
                    ],
                ],
            ],
        ],

        'mortgage_homebuyer_nurture' => [
            'key' => 'mortgage_homebuyer_nurture',
            'name' => 'Mortgage Homebuyer Nurture',
            'description' => 'Mortgage-specific long-term homebuyer nurture sequence.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 1,
            'meta' => [
                'domain' => 'mortgage',
                'strategy' => 'email_primary_sms_supplemental',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Preparation basics',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'mortgage_homebuyer_nurture',
                    'is_active' => true,

                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 14,
                        ],
                    ],

                    'meta' => [
                        'type' => 'message',
                    ],
                ],
            ],
        ],

    ],

];
