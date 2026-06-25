<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled Platform Modules
    |--------------------------------------------------------------------------
    |
    | This is product/onboarding configuration, not a client-facing feature
    | toggle system. Core is always treated as enabled by ModuleManager.
    |
    */

    'enabled' => array_filter(array_map(
        'trim',
        explode(',', env(
            'ENABLED_MODULES',
            'tasks,workflow,flow_routes,messaging,inbound_messaging,internal_notifications,campaigns,webinars,integrations,reporting'
        ))
    )),

    'modules' => [

        'core' => [
            'name' => 'Core CRM',
            'always_on' => true,
            'depends_on' => [],
        ],

        'messaging' => [
            'name' => 'Messaging',
            'depends_on' => ['core'],
        ],

        'inbound_messaging' => [
            'name' => 'Inbound Messaging',
            'depends_on' => ['messaging'],
        ],

        'internal_notifications' => [
            'name' => 'Internal Notifications',
            'depends_on' => ['messaging'],
        ],

        'tasks' => [
            'name' => 'Tasks',
            'depends_on' => ['core'],
        ],

        'workflow' => [
            'name' => 'Workflow',
            'depends_on' => ['core'],
        ],

        'flow_routes' => [
            'name' => 'Flow Routes',
            'depends_on' => ['workflow'],
        ],

        'campaigns' => [
            'name' => 'Campaigns',
            'depends_on' => ['messaging'],
        ],

        'webinars' => [
            'name' => 'Webinars',
            'depends_on' => ['core', 'messaging'],
        ],

        'integrations' => [
            'name' => 'Integrations',
            'depends_on' => ['core'],
        ],

        'reporting' => [
            'name' => 'Reporting',
            'depends_on' => ['core'],
        ],

    ],

];