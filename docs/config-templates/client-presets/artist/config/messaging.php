<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Artist marketing-consent policy
    |--------------------------------------------------------------------------
    |
    | Marketing permission is authoritative at channel + purpose.
    | Message scopes remain useful for message identity/segmentation, but they
    | are not separate marketing-permission silos.
    |
    | Both mappings are declared because the reusable artist_updates Form
    | exposes both email and SMS marketing-consent intents. SMS delivery may
    | remain disabled independently.
    |
    */

    'consent' => [
        'channel_purpose_domains' => [
            'email' => [
                'marketing' => 'marketing',
            ],

            'sms' => [
                'marketing' => 'marketing',
            ],
        ],
    ],

    'consent_domains' => [
        'marketing' => [
            'topic' => 'marketing communications',
            'scopes' => [],
            'scope_prefixes' => [],
            'opt_in' => [],
        ],
    ],
];