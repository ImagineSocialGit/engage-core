<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client Presets
    |--------------------------------------------------------------------------
    |
    | Presets provide starting defaults for common client shapes. They are not
    | business logic and they do not replace explicit client configuration.
    |
    | Final precedence:
    | system config -> selected preset defaults -> explicit client config
    |
    | Runtime systems must read DB-owned definitions. Preset definitions are only
    | starter/default definitions that sync into editable database records.
    |
    */

    'default_package' => env('CLIENT_PRESET'),

    'packages' => [

        'general_contact_engagement' => [
            'modules' => [
                'enabled' => [
                    'tasks',
                    'messaging',
                    'inbound_messaging',
                    'internal_notifications',
                    'campaigns',
                    'broadcasts',
                    'integrations',
                    'reporting',
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
                    'general_default',
                ],
                'tasks' => [
                    'general_default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

        'lightweight_task_workspace' => [
            'modules' => [
                'enabled' => [
                    'tasks',
                    'internal_notifications',
                    'reporting',
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
                    'general_default',
                ],
                'tasks' => [
                    'task_workspace_default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

        'webinar_funnel' => [
            'modules' => [
                'enabled' => [
                    'tasks',
                    'workflow',
                    'flow_routes',
                    'messaging',
                    'inbound_messaging',
                    'internal_notifications',
                    'campaigns',
                    'broadcasts',
                    'webinars',
                    'integrations',
                    'reporting',
                ],
            ],

            'contacts' => [
                'labels' => [
                    'singular' => 'lead',
                    'plural' => 'leads',
                ],

                'routes' => [
                    'plural' => 'leads',
                ],

                'sources' => [
                    'webinar' => [
                        'enabled' => true,
                    ],

                    'website' => [
                        'enabled' => true,
                    ],

                    'manual' => [
                        'enabled' => true,
                    ],
                ],
            ],

            'groups' => [
                'contact_statuses' => [
                    'webinar_default',
                ],
                'tasks' => [
                    'general_default',
                    'webinar_default',
                ],
                'flow_routes' => [
                    'webinar_default',
                ],
                'campaigns' => [
                    'webinar_default',
                ],
            ],
        ],

        'pet_service' => [
            'modules' => [
                'enabled' => [
                    'tasks',
                    'workflow',
                    'messaging',
                    'inbound_messaging',
                    'internal_notifications',
                    'campaigns',
                    'broadcasts',
                    'integrations',
                    'reporting',
                ],
            ],

            'contacts' => [
                'labels' => [
                    'singular' => 'client',
                    'plural' => 'clients',
                ],

                'routes' => [
                    'plural' => 'clients',
                ],

                'sources' => [
                    'website' => [
                        'enabled' => true,
                    ],

                    'manual' => [
                        'enabled' => true,
                    ],
                ],
            ],

            'groups' => [
                'contact_statuses' => [
                    'pet_service_default',
                ],
                'tasks' => [
                    'general_default',
                ],
                'flow_routes' => [],
                'campaigns' => [],
            ],
        ],

        'musician_fan_engagement' => [
            'modules' => [
                'enabled' => [
                    'tasks',
                    'messaging',
                    'inbound_messaging',
                    'internal_notifications',
                    'campaigns',
                    'broadcasts',
                    'integrations',
                    'reporting',
                ],
            ],

            'contacts' => [
                'labels' => [
                    'singular' => 'fan',
                    'plural' => 'fans',
                ],

                'routes' => [
                    'plural' => 'fans',
                ],

                'sources' => [
                    'website' => [
                        'enabled' => true,
                    ],

                    'facebook' => [
                        'enabled' => true,
                    ],

                    'google_ads' => [
                        'enabled' => true,
                    ],

                    'manual' => [
                        'enabled' => true,
                    ],
                ],
            ],

            'groups' => [
                'contact_statuses' => [
                    'musician_fan_default',
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

