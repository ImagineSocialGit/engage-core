<?php

use App\Providers\Modules\CampaignsModuleServiceProvider;
use App\Providers\Modules\CoreModuleServiceProvider;
use App\Providers\Modules\FlowRoutesModuleServiceProvider;
use App\Providers\Modules\InboundMessagingModuleServiceProvider;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Providers\Modules\InternalNotificationsModuleServiceProvider;
use App\Providers\Modules\MessagingModuleServiceProvider;
use App\Providers\Modules\ReportingModuleServiceProvider;
use App\Providers\Modules\TasksModuleServiceProvider;
use App\Providers\Modules\WebinarsModuleServiceProvider;
use App\Providers\Modules\WorkflowModuleServiceProvider;

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
            'providers' => [
                CoreModuleServiceProvider::class,
            ],
        ],

        'messaging' => [
            'name' => 'Messaging',
            'depends_on' => ['core'],
            'providers' => [
                MessagingModuleServiceProvider::class,
            ],
        ],

        'inbound_messaging' => [
            'name' => 'Inbound Messaging',
            'depends_on' => ['messaging'],
            'providers' => [
                InboundMessagingModuleServiceProvider::class,
            ],
        ],

        'internal_notifications' => [
            'name' => 'Internal Notifications',
            'depends_on' => ['messaging'],
            'providers' => [
                InternalNotificationsModuleServiceProvider::class,
            ],
        ],

        'tasks' => [
            'name' => 'Tasks',
            'depends_on' => ['core'],
            'providers' => [
                TasksModuleServiceProvider::class,
            ],
        ],

        'workflow' => [
            'name' => 'Workflow',
            'depends_on' => ['core'],
            'providers' => [
                WorkflowModuleServiceProvider::class,
            ],
        ],

        'flow_routes' => [
            'name' => 'Flow Routes',
            'depends_on' => ['workflow'],
            'providers' => [
                FlowRoutesModuleServiceProvider::class,
            ],
        ],

        'campaigns' => [
            'name' => 'Campaigns',
            'depends_on' => ['messaging'],
            'providers' => [
                CampaignsModuleServiceProvider::class,
            ],
        ],

        'webinars' => [
            'name' => 'Webinars',
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                WebinarsModuleServiceProvider::class,
            ],
        ],

        'integrations' => [
            'name' => 'Integrations',
            'depends_on' => ['core'],
            'providers' => [
                IntegrationsModuleServiceProvider::class,
            ],
        ],

        'reporting' => [
            'name' => 'Reporting',
            'depends_on' => ['core'],
            'providers' => [
                ReportingModuleServiceProvider::class,
            ],
        ],

    ],

];