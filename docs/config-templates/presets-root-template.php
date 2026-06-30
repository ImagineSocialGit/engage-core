<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Root Presets Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/presets.php
    | client/{client-key}/config/presets.php, if client package composition differs
    |
    | This file composes preset packages and tells `php artisan presets:sync`
    | which module preset groups to sync.
    |
    | Preset sync order should remain dependency-safe:
    |
    | 1. contact_statuses
    | 2. tasks
    | 3. campaigns
    | 4. flow_routes
    |
    | Campaigns must sync before FlowRoutes because FlowRoutes may reference
    | campaign_key values in enroll_campaign points.
    */

    'default_package' => env('PRESET_PACKAGE', 'webinar_default'),

    'packages' => [
        'webinar_default' => [
            'name' => 'Webinar Default',
            'description' => 'Vertical-neutral webinar registration, post-event, and nurture setup.',
            'groups' => [
                'contact_statuses' => 'crm_default',
                'tasks' => 'crm_default',
                'campaigns' => 'webinar_default',
                'flow_routes' => 'webinar_default',
            ],
        ],

        'mortgage_default' => [
            'name' => 'Mortgage Default',
            'description' => 'Mortgage-specific preset package that includes webinar nurture plus mortgage homebuyer nurture.',
            'groups' => [
                'contact_statuses' => 'crm_default',
                'tasks' => 'crm_default',
                'campaigns' => 'mortgage_default',
                'flow_routes' => 'webinar_default',
            ],
        ],
    ],

    'sync_order' => [
        'contact_statuses',
        'tasks',
        'campaigns',
        'flow_routes',
    ],

];