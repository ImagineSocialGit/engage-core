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

    'default' => env('CLIENT_PRESET'),

    'presets' => [

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

            'contact_statuses' => [
                'groups' => [
                    'general_default',
                ],
            ],

            'tasks' => [
                'groups' => [
                    'general_default',
                ],
            ],

            'flow_routes' => [
                'groups' => [],
            ],

            'campaigns' => [
                'groups' => [],
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

            'contact_statuses' => [
                'groups' => [
                    'general_default',
                ],
            ],

            'tasks' => [
                'groups' => [
                    'task_workspace_default',
                ],
            ],

            'flow_routes' => [
                'groups' => [],
            ],

            'campaigns' => [
                'groups' => [],
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

            'contact_statuses' => [
                'groups' => [
                    'webinar_default',
                ],
            ],

            'tasks' => [
                'groups' => [
                    'general_default',
                    'webinar_default',
                ],
            ],

            'flow_routes' => [
                'groups' => [
                    'webinar_default',
                ],
            ],

            'campaigns' => [
                'groups' => [
                    'webinar_default',
                ],
            ],
        ],

        'mortgage' => [
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
                    'mortgage',
                    'integrations',
                    'reporting',
                ],
            ],

            'contacts' => [
                'labels' => [
                    'singular' => 'borrower',
                    'plural' => 'borrowers',
                ],

                'routes' => [
                    'plural' => 'borrowers',
                ],

                'sources' => [
                    'webinar' => [
                        'enabled' => true,
                    ],

                    'website' => [
                        'enabled' => true,
                    ],

                    'realtor' => [
                        'enabled' => true,
                    ],

                    'zillow' => [
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

            'contact_statuses' => [
                'groups' => [
                    'mortgage_default',
                ],
            ],

            'tasks' => [
                'groups' => [
                    'general_default',
                    'mortgage_default',
                ],
            ],

            'flow_routes' => [
                'groups' => [
                    'mortgage_default',
                ],
            ],

            'campaigns' => [
                'groups' => [
                    'mortgage_default',
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

            'contact_statuses' => [
                'groups' => [
                    'pet_service_default',
                ],
            ],

            'tasks' => [
                'groups' => [
                    'general_default',
                ],
            ],

            'flow_routes' => [
                'groups' => [],
            ],

            'campaigns' => [
                'groups' => [],
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

            'contact_statuses' => [
                'groups' => [
                    'musician_fan_default',
                ],
            ],

            'tasks' => [
                'groups' => [
                    'general_default',
                ],
            ],

            'flow_routes' => [
                'groups' => [],
            ],

            'campaigns' => [
                'groups' => [],
            ],
        ],

    ],

];