<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client Preset Packages
    |--------------------------------------------------------------------------
    |
    | Core intentionally ships with a small generic package surface.
    | Rich vertical/client packages belong in client config.
    |
    | Runtime systems read DB-owned definitions. Preset packages select starter
    | definitions that are materialized by preset sync. Runtime module
    | availability belongs exclusively to config('modules.enabled').
    |
    */

    'default_package' => 'basic',

    'packages' => [

        'basic' => [
            'contacts' => [
                'labels' => [
                    'singular' => 'contact',
                    'plural' => 'contacts',
                ],

                'routes' => [
                    'plural' => 'contacts',
                ],
            ],

            'groups' => [
                'contact_statuses' => [
                    'default',
                ],
                'tasks' => [
                    'default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

        'messaging' => [
            'contacts' => [
                'labels' => [
                    'singular' => 'contact',
                    'plural' => 'contacts',
                ],

                'routes' => [
                    'plural' => 'contacts',
                ],
            ],

            'groups' => [
                'contact_statuses' => [
                    'default',
                ],
                'tasks' => [
                    'default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

        'automated_messaging' => [
            'contacts' => [
                'labels' => [
                    'singular' => 'contact',
                    'plural' => 'contacts',
                ],

                'routes' => [
                    'plural' => 'contacts',
                ],
            ],

            'groups' => [
                'contact_statuses' => [
                    'default',
                ],
                'tasks' => [
                    'default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

    ],

];