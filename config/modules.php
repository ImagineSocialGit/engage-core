<?php

use App\Modules\Broadcasts\Providers\BroadcastsModuleServiceProvider;
use App\Modules\Campaigns\Providers\CampaignsModuleServiceProvider;
use App\Modules\Core\Providers\CoreModuleServiceProvider;
use App\Modules\FlowRoutes\Providers\FlowRoutesModuleServiceProvider;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Modules\InboundMessaging\Providers\InboundMessagingModuleServiceProvider;
use App\Modules\InternalNotifications\Providers\InternalNotificationsModuleServiceProvider;
use App\Modules\Messaging\Providers\MessagingModuleServiceProvider;
use App\Modules\Mortgage\Providers\MortgageModuleServiceProvider;
use App\Modules\Portal\Providers\PortalModuleServiceProvider;
use App\Modules\Reporting\Providers\ReportingModuleServiceProvider;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Tasks\Providers\TasksModuleServiceProvider;
use App\Modules\Webinars\Providers\WebinarsModuleServiceProvider;
use App\Modules\Workflow\Providers\WorkflowModuleServiceProvider;
use App\Providers\Modules\IntegrationsModuleServiceProvider;

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
            'tasks,workflow,flow_routes,messaging,inbound_messaging,internal_notifications,campaigns,broadcasts,webinars,integrations,reporting'
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
            'depends_on' => ['core', 'messaging'],
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


        'scheduling' => [
            'name' => 'Scheduling',
            'depends_on' => ['core'],
            'providers' => [
                SchedulingModuleServiceProvider::class,
            ],
        ],

        'portal' => [
            'name' => 'Portal',
            'depends_on' => ['core'],
            'providers' => [
                PortalModuleServiceProvider::class,
            ],
        ],


        'forms' => [
            'name' => 'Forms',
            'depends_on' => ['core'],
            'providers' => [
                FormsModuleServiceProvider::class,
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
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                CampaignsModuleServiceProvider::class,
            ],
        ],

        'broadcasts' => [
            'name' => 'Broadcasts',
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                BroadcastsModuleServiceProvider::class,
            ],
        ],

        'webinars' => [
            'name' => 'Webinars',
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                WebinarsModuleServiceProvider::class,
            ],
        ],

        'mortgage' => [
            'name' => 'Mortgage',
            'depends_on' => ['core'],
            'providers' => [
                MortgageModuleServiceProvider::class,
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
