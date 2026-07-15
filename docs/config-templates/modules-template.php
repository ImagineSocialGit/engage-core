<?php

use App\Modules\Broadcasts\Providers\BroadcastsModuleServiceProvider;
use App\Modules\Campaigns\Providers\CampaignsModuleServiceProvider;
use App\Modules\Commerce\Providers\CommerceModuleServiceProvider;
use App\Modules\Core\Providers\CoreModuleServiceProvider;
use App\Modules\Documents\Providers\DocumentsModuleServiceProvider;
use App\Modules\FlowRoutes\Providers\FlowRoutesModuleServiceProvider;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Modules\InboundMessaging\Providers\InboundMessagingModuleServiceProvider;
use App\Modules\InternalNotifications\Providers\InternalNotificationsModuleServiceProvider;
use App\Modules\Location\Providers\LocationModuleServiceProvider;
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
    | Modules Template
    |--------------------------------------------------------------------------
    |
    | File paths:
    | config/modules.php
    | client/{client-key}/config/modules.php
    |
    | This is product/onboarding configuration, not a client-facing feature
    | toggle system. Core owns installed module definitions. The selected client
    | owns its explicit runtime-enabled module list in client config.
    |
    | Core is always treated as enabled by ModuleManager.
    |
    | enabled controls explicit feature visibility. Providers may load as
    | dependencies without making a module visible.
    |
    | Shared schema may include optional relationships between universal
    | modules. That does not automatically make the target module visible.
    | Example: Scheduling can optionally reference saved Location records while
    | Scheduling still depends only on Core for feature visibility.
    |
    | Keep dependency direction one-way and intentional. Do not enable vertical
    | modules unless that vertical is installed.
    |
    | Runtime providers and preset contributors are separate concerns.
    | `providers` participate in runtime module bootstrapping.
    | `preset_contributors` expose preset definitions independently of runtime
    | module enablement so package composition can discover all installed
    | contributions.
    |
    | Preset contributor registration is explicit. Do not scan directories for
    | contribution files.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Setup validation expectations
    |--------------------------------------------------------------------------
    |
    | Module validation should reject unknown enabled module keys and impossible
    | dependency graphs. Explicit feature visibility remains distinct from provider
    | loading for dependencies.
    |
    | Other setup validators should use the canonical module manager/provider state
    | rather than duplicating ad hoc module-enabled checks.
    |
    */

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

    'modules' => [

        'core' => [
            'name' => 'Core CRM',
            'always_on' => true,
            'depends_on' => [],
            'providers' => [
                CoreModuleServiceProvider::class,
            ],
            'preset_contributors' => [
                \App\Modules\Core\Presets\CorePresetContributor::class,
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
            'preset_contributors' => [
                \App\Modules\Tasks\Presets\TasksPresetContributor::class,
            ],
        ],

        'location' => [
            'name' => 'Location',
            'depends_on' => ['core'],
            'providers' => [
                LocationModuleServiceProvider::class,
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

        'documents' => [
            'name' => 'Documents',
            'depends_on' => ['core'],
            'providers' => [
                DocumentsModuleServiceProvider::class,
            ],
        ],

        'commerce' => [
            'name' => 'Commerce',
            'depends_on' => ['core'],
            'providers' => [
                CommerceModuleServiceProvider::class,
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
            'preset_contributors' => [
                \App\Modules\Webinars\Presets\WebinarsPresetContributor::class,
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
