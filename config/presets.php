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
    | definitions that are materialized by preset sync.
    |
    */

    'default_package' => 'basic',

    'packages' => [

        'basic' => [
            'modules' => [
                'enabled' => [
                    'tasks',
                    'workflow',
                ],
            ],

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
                    'general_default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

        'messaging' => [
            'modules' => [
                'enabled' => [
                    'tasks',
                    'workflow',
                    'messaging',
                    'inbound_messaging',
                    'internal_notifications',
                    'broadcasts',
                ],
            ],

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
                    'general_default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

        'automated_messaging' => [
            'modules' => [
                'enabled' => [
                    'tasks',
                    'workflow',
                    'flow_routes',
                    'messaging',
                    'inbound_messaging',
                    'internal_notifications',
                    'broadcasts',
                ],
            ],

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
                    'general_default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

    ],

];
