<?php

use App\Modules\Broadcasts\Providers\BroadcastsModuleServiceProvider;
use App\Modules\Campaigns\Providers\CampaignsModuleServiceProvider;
use App\Modules\Core\Providers\CoreModuleServiceProvider;
use App\Modules\Commerce\Providers\CommerceModuleServiceProvider;
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



    /*
    |--------------------------------------------------------------------------
    | Module Wayfinding Tones
    |--------------------------------------------------------------------------
    |
    | These tones are quiet orientation cues, not urgency colors. Keep normal
    | module UI muted. Reserve stronger amber/red treatments for overdue,
    | failed, blocked, or business-critical states.
    |
    */

    'tones' => [
        'slate' => [
            'panel' => 'bg-slate-50/70 border-slate-200',
            'panel_focus' => '!bg-slate-100 ring-2 ring-slate-400',
            'item' => 'bg-slate-50 ring-slate-200',
            'item_focus' => '!bg-slate-200 ring-slate-400',
            'jump' => 'bg-slate-50/70 ring-slate-200 hover:bg-slate-100 hover:ring-slate-300 focus:ring-slate-300',
            'badge' => 'bg-slate-100 text-slate-700 ring-slate-200',
            'rail' => 'border-l-slate-200',
            'text' => 'text-slate-700',
        ],
        'sky' => [
            'panel' => 'bg-sky-50/30 border-sky-100',
            'panel_focus' => '!bg-sky-100/70 ring-2 ring-sky-300',
            'item' => 'bg-sky-50/30 ring-sky-100',
            'item_focus' => '!bg-sky-100/70 ring-sky-300',
            'jump' => 'bg-sky-50/30 ring-sky-100 hover:bg-sky-50/70 hover:ring-sky-200 focus:ring-sky-200',
            'badge' => 'bg-sky-50 text-sky-700 ring-sky-200',
            'rail' => 'border-l-sky-200',
            'text' => 'text-sky-700',
        ],
        'blue' => [
            'panel' => 'bg-blue-50/30 border-blue-100',
            'panel_focus' => '!bg-blue-100/70 ring-2 ring-blue-300',
            'item' => 'bg-blue-50/30 ring-blue-100',
            'item_focus' => '!bg-blue-100/70 ring-blue-300',
            'jump' => 'bg-blue-50/30 ring-blue-100 hover:bg-blue-50/70 hover:ring-blue-200 focus:ring-blue-200',
            'badge' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'rail' => 'border-l-blue-200',
            'text' => 'text-blue-700',
        ],
        'indigo' => [
            'panel' => 'bg-indigo-50/30 border-indigo-100',
            'panel_focus' => '!bg-indigo-100/70 ring-2 ring-indigo-300',
            'item' => 'bg-indigo-50/30 ring-indigo-100',
            'item_focus' => '!bg-indigo-100/70 ring-indigo-300',
            'jump' => 'bg-indigo-50/30 ring-indigo-100 hover:bg-indigo-50/70 hover:ring-indigo-200 focus:ring-indigo-200',
            'badge' => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
            'rail' => 'border-l-indigo-200',
            'text' => 'text-indigo-700',
        ],
        'violet' => [
            'panel' => 'bg-violet-50/30 border-violet-100',
            'panel_focus' => '!bg-violet-100/70 ring-2 ring-violet-300',
            'item' => 'bg-violet-50/30 ring-violet-100',
            'item_focus' => '!bg-violet-100/70 ring-violet-300',
            'jump' => 'bg-violet-50/30 ring-violet-100 hover:bg-violet-50/70 hover:ring-violet-200 focus:ring-violet-200',
            'badge' => 'bg-violet-50 text-violet-700 ring-violet-200',
            'rail' => 'border-l-violet-200',
            'text' => 'text-violet-700',
        ],
        'teal' => [
            'panel' => 'bg-teal-50/30 border-teal-100',
            'panel_focus' => '!bg-teal-100/70 ring-2 ring-teal-300',
            'item' => 'bg-teal-50/30 ring-teal-100',
            'item_focus' => '!bg-teal-100/70 ring-teal-300',
            'jump' => 'bg-teal-50/30 ring-teal-100 hover:bg-teal-50/70 hover:ring-teal-200 focus:ring-teal-200',
            'badge' => 'bg-teal-50 text-teal-700 ring-teal-200',
            'rail' => 'border-l-teal-200',
            'text' => 'text-teal-700',
        ],
        'cyan' => [
            'panel' => 'bg-cyan-50/30 border-cyan-100',
            'panel_focus' => '!bg-cyan-100/70 ring-2 ring-cyan-300',
            'item' => 'bg-cyan-50/30 ring-cyan-100',
            'item_focus' => '!bg-cyan-100/70 ring-cyan-300',
            'jump' => 'bg-cyan-50/30 ring-cyan-100 hover:bg-cyan-50/70 hover:ring-cyan-200 focus:ring-cyan-200',
            'badge' => 'bg-cyan-50 text-cyan-700 ring-cyan-200',
            'rail' => 'border-l-cyan-200',
            'text' => 'text-cyan-700',
        ],
        'fuchsia' => [
            'panel' => 'bg-fuchsia-50/30 border-fuchsia-100',
            'panel_focus' => '!bg-fuchsia-100/70 ring-2 ring-fuchsia-300',
            'item' => 'bg-fuchsia-50/30 ring-fuchsia-100',
            'item_focus' => '!bg-fuchsia-100/70 ring-fuchsia-300',
            'jump' => 'bg-fuchsia-50/30 ring-fuchsia-100 hover:bg-fuchsia-50/70 hover:ring-fuchsia-200 focus:ring-fuchsia-200',
            'badge' => 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200',
            'rail' => 'border-l-fuchsia-200',
            'text' => 'text-fuchsia-700',
        ],
        'stone' => [
            'panel' => 'bg-stone-50/70 border-stone-200',
            'panel_focus' => '!bg-stone-100 ring-2 ring-stone-300',
            'item' => 'bg-stone-50/70 ring-stone-200',
            'item_focus' => '!bg-stone-100 ring-stone-300',
            'jump' => 'bg-stone-50/70 ring-stone-200 hover:bg-stone-100 hover:ring-stone-300 focus:ring-stone-300',
            'badge' => 'bg-stone-100 text-stone-700 ring-stone-200',
            'rail' => 'border-l-stone-200',
            'text' => 'text-stone-700',
        ],
        'emerald' => [
            'panel' => 'bg-emerald-50/30 border-emerald-100',
            'panel_focus' => '!bg-emerald-100/70 ring-2 ring-emerald-300',
            'item' => 'bg-emerald-50/30 ring-emerald-100',
            'item_focus' => '!bg-emerald-100/70 ring-emerald-300',
            'jump' => 'bg-emerald-50/30 ring-emerald-100 hover:bg-emerald-50/70 hover:ring-emerald-200 focus:ring-emerald-200',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'rail' => 'border-l-emerald-200',
            'text' => 'text-emerald-700',
        ],
        'lime' => [
            'panel' => 'bg-lime-50/30 border-lime-100',
            'panel_focus' => '!bg-lime-100/70 ring-2 ring-lime-300',
            'item' => 'bg-lime-50/30 ring-lime-100',
            'item_focus' => '!bg-lime-100/70 ring-lime-300',
            'jump' => 'bg-lime-50/30 ring-lime-100 hover:bg-lime-50/70 hover:ring-lime-200 focus:ring-lime-200',
            'badge' => 'bg-lime-50 text-lime-700 ring-lime-200',
            'rail' => 'border-l-lime-200',
            'text' => 'text-lime-700',
        ],
        'amber' => [
            'panel' => 'bg-amber-50/30 border-amber-100',
            'panel_focus' => '!bg-amber-100/70 ring-2 ring-amber-300',
            'item' => 'bg-amber-50/30 ring-amber-100',
            'item_focus' => '!bg-amber-100/70 ring-amber-300',
            'jump' => 'bg-amber-50/30 ring-amber-100 hover:bg-amber-50/70 hover:ring-amber-200 focus:ring-amber-200',
            'badge' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'rail' => 'border-l-amber-200',
            'text' => 'text-amber-700',
        ],
        'orange' => [
            'panel' => 'bg-orange-50/30 border-orange-100',
            'panel_focus' => '!bg-orange-100/70 ring-2 ring-orange-300',
            'item' => 'bg-orange-50/30 ring-orange-100',
            'item_focus' => '!bg-orange-100/70 ring-orange-300',
            'jump' => 'bg-orange-50/30 ring-orange-100 hover:bg-orange-50/70 hover:ring-orange-200 focus:ring-orange-200',
            'badge' => 'bg-orange-50 text-orange-700 ring-orange-200',
            'rail' => 'border-l-orange-200',
            'text' => 'text-orange-700',
        ],
        'rose' => [
            'panel' => 'bg-rose-50/30 border-rose-100',
            'panel_focus' => '!bg-rose-100/70 ring-2 ring-rose-300',
            'item' => 'bg-rose-50/30 ring-rose-100',
            'item_focus' => '!bg-rose-100/70 ring-rose-300',
            'jump' => 'bg-rose-50/30 ring-rose-100 hover:bg-rose-50/70 hover:ring-rose-200 focus:ring-rose-200',
            'badge' => 'bg-rose-50 text-rose-700 ring-rose-200',
            'rail' => 'border-l-rose-200',
            'text' => 'text-rose-700',
        ],
        'pink' => [
            'panel' => 'bg-pink-50/30 border-pink-100',
            'panel_focus' => '!bg-pink-100/70 ring-2 ring-pink-300',
            'item' => 'bg-pink-50/30 ring-pink-100',
            'item_focus' => '!bg-pink-100/70 ring-pink-300',
            'jump' => 'bg-pink-50/30 ring-pink-100 hover:bg-pink-50/70 hover:ring-pink-200 focus:ring-pink-200',
            'badge' => 'bg-pink-50 text-pink-700 ring-pink-200',
            'rail' => 'border-l-pink-200',
            'text' => 'text-pink-700',
        ],
        'purple' => [
            'panel' => 'bg-purple-50/30 border-purple-100',
            'panel_focus' => '!bg-purple-100/70 ring-2 ring-purple-300',
            'item' => 'bg-purple-50/30 ring-purple-100',
            'item_focus' => '!bg-purple-100/70 ring-purple-300',
            'jump' => 'bg-purple-50/30 ring-purple-100 hover:bg-purple-50/70 hover:ring-purple-200 focus:ring-purple-200',
            'badge' => 'bg-purple-50 text-purple-700 ring-purple-200',
            'rail' => 'border-l-purple-200',
            'text' => 'text-purple-700',
        ],
        'zinc' => [
            'panel' => 'bg-zinc-50/70 border-zinc-200',
            'panel_focus' => '!bg-zinc-100 ring-2 ring-zinc-300',
            'item' => 'bg-zinc-50/70 ring-zinc-200',
            'item_focus' => '!bg-zinc-100 ring-zinc-300',
            'jump' => 'bg-zinc-50/70 ring-zinc-200 hover:bg-zinc-100 hover:ring-zinc-300 focus:ring-zinc-300',
            'badge' => 'bg-zinc-100 text-zinc-700 ring-zinc-200',
            'rail' => 'border-l-zinc-200',
            'text' => 'text-zinc-700',
        ],
    ],

    'modules' => [

        'core' => [
            'name' => 'Core CRM',
            'ui' => [
                'tone' => 'slate',
            ],
            'always_on' => true,
            'depends_on' => [],
            'providers' => [
                CoreModuleServiceProvider::class,
            ],
        ],

        'messaging' => [
            'name' => 'Messaging',
            'ui' => [
                'tone' => 'sky',
            ],
            'depends_on' => ['core'],
            'providers' => [
                MessagingModuleServiceProvider::class,
            ],
        ],

        'inbound_messaging' => [
            'name' => 'Inbound Messaging',
            'ui' => [
                'tone' => 'blue',
            ],
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                InboundMessagingModuleServiceProvider::class,
            ],
        ],

        'internal_notifications' => [
            'name' => 'Internal Notifications',
            'ui' => [
                'tone' => 'indigo',
            ],
            'depends_on' => ['messaging'],
            'providers' => [
                InternalNotificationsModuleServiceProvider::class,
            ],
        ],

        'tasks' => [
            'name' => 'Tasks',
            'ui' => [
                'tone' => 'violet',
            ],
            'depends_on' => ['core'],
            'providers' => [
                TasksModuleServiceProvider::class,
            ],
        ],


        'scheduling' => [
            'name' => 'Scheduling',
            'ui' => [
                'tone' => 'teal',
            ],
            'depends_on' => ['core'],
            'providers' => [
                SchedulingModuleServiceProvider::class,
            ],
        ],

        'portal' => [
            'name' => 'Portal',
            'ui' => [
                'tone' => 'cyan',
            ],
            'depends_on' => ['core'],
            'providers' => [
                PortalModuleServiceProvider::class,
            ],
        ],


        'forms' => [
            'name' => 'Forms',
            'ui' => [
                'tone' => 'fuchsia',
            ],
            'depends_on' => ['core'],
            'providers' => [
                FormsModuleServiceProvider::class,
            ],
        ],

        'documents' => [
            'name' => 'Documents',
            'ui' => [
                'tone' => 'stone',
            ],
            'depends_on' => ['core'],
            'providers' => [
                DocumentsModuleServiceProvider::class,
            ],
        ],

        'commerce' => [
            'name' => 'Commerce',
            'ui' => [
                'tone' => 'emerald',
            ],
            'depends_on' => ['core'],
            'providers' => [
                CommerceModuleServiceProvider::class,
            ],
        ],


        'location' => [
            'name' => 'Location',
            'ui' => [
                'tone' => 'lime',
            ],
            'depends_on' => ['core'],
            'providers' => [
                LocationModuleServiceProvider::class,
            ],
        ],

        'workflow' => [
            'name' => 'Workflow',
            'ui' => [
                'tone' => 'amber',
            ],
            'depends_on' => ['core'],
            'providers' => [
                WorkflowModuleServiceProvider::class,
            ],
        ],

        'flow_routes' => [
            'name' => 'Flow Routes',
            'ui' => [
                'tone' => 'orange',
            ],
            'depends_on' => ['workflow'],
            'providers' => [
                FlowRoutesModuleServiceProvider::class,
            ],
        ],

        'campaigns' => [
            'name' => 'Campaigns',
            'ui' => [
                'tone' => 'rose',
            ],
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                CampaignsModuleServiceProvider::class,
            ],
        ],

        'broadcasts' => [
            'name' => 'Broadcasts',
            'ui' => [
                'tone' => 'pink',
            ],
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                BroadcastsModuleServiceProvider::class,
            ],
        ],

        'webinars' => [
            'name' => 'Webinars',
            'ui' => [
                'tone' => 'emerald',
            ],
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                WebinarsModuleServiceProvider::class,
            ],
        ],

        'mortgage' => [
            'name' => 'Mortgage',
            'ui' => [
                'tone' => 'zinc',
            ],
            'depends_on' => ['core'],
            'providers' => [
                MortgageModuleServiceProvider::class,
            ],
        ],

        'integrations' => [
            'name' => 'Integrations',
            'ui' => [
                'tone' => 'purple',
            ],
            'depends_on' => ['core'],
            'providers' => [
                IntegrationsModuleServiceProvider::class,
            ],
        ],

        'reporting' => [
            'name' => 'Reporting',
            'ui' => [
                'tone' => 'slate',
            ],
            'depends_on' => ['core'],
            'providers' => [
                ReportingModuleServiceProvider::class,
            ],
        ],

    ],

];
