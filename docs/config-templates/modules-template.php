<?php

use App\Modules\Broadcasts\Providers\BroadcastsModuleServiceProvider;
use App\Modules\Campaigns\Providers\CampaignsModuleServiceProvider;
use App\Modules\Core\Providers\CoreModuleServiceProvider;
use App\Modules\FlowRoutes\Providers\FlowRoutesModuleServiceProvider;
use App\Modules\InboundMessaging\Providers\InboundMessagingModuleServiceProvider;
use App\Modules\InternalNotifications\Providers\InternalNotificationsModuleServiceProvider;
use App\Modules\Messaging\Providers\MessagingModuleServiceProvider;
use App\Modules\Mortgage\Providers\MortgageModuleServiceProvider;
use App\Modules\Reporting\Providers\ReportingModuleServiceProvider;
use App\Modules\Tasks\Providers\TasksModuleServiceProvider;
use App\Modules\Webinars\Providers\WebinarsModuleServiceProvider;
use App\Modules\Workflow\Providers\WorkflowModuleServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Modules Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/modules.php
    |
    | enabled controls explicit feature visibility.
    | Providers may load as dependencies without making a module visible.
    |
    | Keep dependency direction one-way and intentional.
    | Do not enable vertical modules unless that vertical is installed.
    */

    'modules' => [
        'core' => [
            'name' => 'Core',
            'enabled' => true,
            'provider' => CoreModuleServiceProvider::class,
            'depends_on' => [],
        ],

        'messaging' => [
            'name' => 'Messaging',
            'enabled' => true,
            'provider' => MessagingModuleServiceProvider::class,
            'depends_on' => ['core'],
        ],

        'webinars' => [
            'name' => 'Webinars',
            'enabled' => true,
            'provider' => WebinarsModuleServiceProvider::class,
            'depends_on' => ['core', 'messaging'],
        ],

        'workflow' => [
            'name' => 'Workflow',
            'enabled' => true,
            'provider' => WorkflowModuleServiceProvider::class,
            'depends_on' => ['core'],
        ],

        'campaigns' => [
            'name' => 'Campaigns',
            'enabled' => true,
            'provider' => CampaignsModuleServiceProvider::class,
            'depends_on' => ['core', 'messaging'],
        ],

        'flow_routes' => [
            'name' => 'FlowRoutes',
            'enabled' => true,
            'provider' => FlowRoutesModuleServiceProvider::class,
            'depends_on' => ['workflow'],
        ],

        'tasks' => [
            'name' => 'Tasks',
            'enabled' => true,
            'provider' => TasksModuleServiceProvider::class,
            'depends_on' => ['core'],
        ],

        'inbound_messaging' => [
            'name' => 'Inbound Messaging',
            'enabled' => false,
            'provider' => InboundMessagingModuleServiceProvider::class,
            'depends_on' => ['core', 'messaging'],
        ],

        'internal_notifications' => [
            'name' => 'Internal Notifications',
            'enabled' => false,
            'provider' => InternalNotificationsModuleServiceProvider::class,
            'depends_on' => ['messaging'],
        ],

        'broadcasts' => [
            'name' => 'Broadcasts',
            'enabled' => false,
            'provider' => BroadcastsModuleServiceProvider::class,
            'depends_on' => ['core', 'messaging'],
        ],

        'reporting' => [
            'name' => 'Reporting',
            'enabled' => false,
            'provider' => ReportingModuleServiceProvider::class,
            'depends_on' => ['core'],
        ],

        'mortgage' => [
            'name' => 'Mortgage',
            'enabled' => false,
            'provider' => MortgageModuleServiceProvider::class,
            'depends_on' => [
                'core',
                'workflow',
                'flow_routes',
                'tasks',
                'messaging',
                'campaigns',
                'webinars',
                'reporting',
            ],
        ],
    ],

];