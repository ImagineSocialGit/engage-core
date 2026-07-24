<?php

/*
| The executable definition shape is owned by the registered
| `campaigns.preset_definition` config contract and CampaignPresetDefinition.
| Unsupported fields must fail validation instead of becoming compatibility aliases.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign Presets Template
    |--------------------------------------------------------------------------
    |
    | Example module-first paths:
    | config/presets/modules/webinars/campaigns.php
    | config/presets/modules/{contributor-module}/campaigns.php
    | client/{client-key}/config/presets/modules/{contributor-module}/campaigns.php
    |
    | Canonical compact authoring rules:
    | - the definitions map key is the Campaign key
    | - steps are a sequential list; list position derives step_number
    | - variants are a keyed map; map key derives the variant key
    | - variant map order derives sort_order in increments of 10
    | - Campaign purpose, scope, and source_version cascade to children
    | - campaign_step_due is the fixed dispatch key for every step and variant
    | - Campaign variant_strategy cascades unless a step explicitly overrides it
    | - Campaign and step channel summaries are derived from their variants
    | - omit child is_active when true; include it only to disable that child
    | - omit empty criteria, dependency_rules, and meta objects
    |
    | Do not author derived fields such as Campaign key/channel/dispatch_key, step_number,
    | step dispatch/channel/purpose/scope, or variant key/sort_order/dispatch/purpose/scope.
    |
    | Campaign `status` is the sole top-level lifecycle control. The preset value
    | is only the installation default; routine sync does not overwrite existing
    | operational status. Use the Campaign CRM lifecycle controls or
    | campaigns:deactivate for operational shutdown.
    |
    | Campaign presets own journey order, timing, strategy, dependency behavior,
    | and references to Messaging-owned templates. They must not own reusable
    | subject/body/CTA copy or payload overrides.
    |
    | Messaging campaign templates resolve through:
    | messaging.{channel}.definitions.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}
    |
    | source_config_path remains optional provenance/fallback metadata. Semantic
    | identity is Campaign key + derived step number + variant key + channel +
    | inherited purpose/scope.
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
            'name' => 'Webinar Attended Nurture',
            'description' => 'Follow-up sequence for contacts who attended a webinar.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'variant_strategy' => 'dependency_aware',
            'source_version' => 1,
            'meta' => [
                'domain' => 'webinar',
                'strategy' => 'sms_preferred_email_fallback',
            ],
            'steps' => [
                [
                    'name' => 'Attended one-week follow-up',
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
                            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
                        ],
                        'email' => [
                            'name' => 'Email fallback',
                            'channel' => 'email',
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
            'name' => 'Webinar Missed Nurture',
            'description' => 'Follow-up sequence for contacts who registered for a webinar but missed it.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'steps' => [
                [
                    'name' => 'Missed one-week follow-up',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 7,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                        ],
                    ],
                ],
            ],
        ],

        'mortgage_homebuyer_nurture' => [
            'name' => 'Mortgage Homebuyer Nurture',
            'description' => 'Mortgage-specific long-term homebuyer nurture sequence.',
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'status' => 'inactive',
            'variant_strategy' => 'send_all_eligible',
            'source_version' => 1,
            'meta' => [
                'domain' => 'mortgage',
            ],
            'steps' => [
                [
                    'name' => 'Preparation basics',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 14,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'name' => 'Email follow-up',
                            'channel' => 'email',
                        ],
                        'sms' => [
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                            'is_active' => false,
                        ],
                    ],
                ],
            ],
        ],
    ],
];