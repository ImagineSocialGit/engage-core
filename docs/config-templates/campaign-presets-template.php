<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign Presets Template
    |--------------------------------------------------------------------------
    |
    | Example module-first file paths:
    | config/presets/modules/webinars/campaigns.php
    | config/presets/modules/{contributor-module}/campaigns.php
    | client/{client-key}/config/presets/modules/{contributor-module}/campaigns.php
    |
    | Campaign = enrolled multi-step journey.
    |
    | Campaign presets own:
    | - campaign identity
    | - step order
    | - step/variant timing
    | - step/variant conditions
    | - variant strategy
    | - variant dependencies and enablement
    | - message template references through variants
    |
    | Messaging configs own:
    | - reusable message copy
    | - subject/body/CTA payloads
    | - payload_class
    | - delivery queue
    |
    | Campaigns resolves Campaign-owned behavior first. The shared
    | ResolvedMessageDispatchBuilder combines that behavior with the selected
    | reusable Messaging template into the normalized dispatch contract.
    |
    | Campaign presets must not own reusable subject/body copy.
    | Campaign presets must not define or override payloads.
    |
    | Preset sync has no Campaign force mode right now. Normal Campaign sync
    | updates non-customized Campaigns/Steps/Variants, removes stale
    | non-customized steps/variants, and preserves customized records.
    | Do not rely on presets:sync as a destructive Campaign reset.
    |
    | Campaign preset variants reference Messaging templates with first-class:
    | - key
    | - dispatch_key
    | - channel
    | - purpose
    | - scope
    |
    | Do not use meta.message for new Campaign preset step message references.
    |
    | Campaign message templates resolve by:
    |
    | messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}
    */

    /*
    |--------------------------------------------------------------------------
    | Validation expectations
    |--------------------------------------------------------------------------
    |
    | Shared preset-composition validation owns package/group/definition structure,
    | including missing selected groups and duplicate contributed group/definition
    | keys.
    |
    | Campaigns owns semantic validation of selected Campaign/Step/Variant
    | definitions: shape, strategy, timing, dependency rules, stable variant
    | identity, and Messaging template context references.
    |
    | Missing required templates or impossible dependency references are hard
    | errors when they make intended runtime behavior unsafe or impossible.
    | Dormant or unused definitions may be warnings when runtime remains safe.
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
        ],
    ],

    'definitions' => [

        'webinar_attended_nurture' => [
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'description' => 'Follow-up sequence for contacts who attended a webinar.',
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
                    'is_active' => true,
                    'variant_strategy' => 'first_available',

                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],

                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'sort_order' => 0,
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],

                        // Add only when the SMS Messaging template exists and
                        // the campaigns surface exposes SMS through Messaging
                        // channel availability.
                        [
                            'key' => 'sms',
                            'name' => 'SMS follow-up',
                            'sort_order' => 1,
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],

                    'meta' => [
                        'type' => 'message',
                    ],
                ],

                [
                    'step_number' => 2,
                    'name' => 'Common next-step questions',
                    'is_active' => true,
                    'variant_strategy' => 'first_available',

                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 3,
                        ],
                    ],

                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'sort_order' => 0,
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
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
            'description' => 'Follow-up sequence for contacts who registered for a webinar but missed it.',
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
                    'is_active' => true,
                    'variant_strategy' => 'first_available',

                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],

                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'sort_order' => 0,
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
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
                    'is_active' => true,
                    'variant_strategy' => 'first_available',

                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 14,
                        ],
                    ],

                    'variants' => [
                        [
                            'key' => 'email',
                            'name' => 'Email follow-up',
                            'sort_order' => 0,
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'mortgage_homebuyer_nurture',
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
