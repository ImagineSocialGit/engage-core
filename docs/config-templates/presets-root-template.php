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
    |
    | Modules with no preset sync, such as Commerce or Location foundations,
    | should not appear in sync_order until they own DB-backed preset sync.
    */

    'default_package' => env('PRESET_PACKAGE', 'webinar_default'),

    /*
    |--------------------------------------------------------------------------
    | Setup validation contract
    |--------------------------------------------------------------------------
    |
    | Preset packages are validated through the shared setup-validation manager.
    | Owning modules contribute validators for their own config/preset shapes and
    | cross-references. Validation findings use one reusable structured shape and
    | may be consumed by CLI commands now and authoring/readiness UI later.
    |
    | Hard errors represent unsafe or impossible intended runtime behavior.
    | Warnings represent dormant, unused, discouraged, or surprising-but-safe
    | configuration.
    |
    | Do not persist validation runs/findings unless a concrete operator workflow
    | proves historical validation state is needed.
    |
    */

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
