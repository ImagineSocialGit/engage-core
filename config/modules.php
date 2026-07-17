<?php

use App\Modules\Broadcasts\Providers\BroadcastsModuleServiceProvider;
use App\Modules\Campaigns\Providers\CampaignsModuleServiceProvider;
use App\Modules\Core\Presets\CorePresetContributor;
use App\Support\Presets\ClientPresetContributor;
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
use App\Modules\Tasks\Presets\TasksPresetContributor;
use App\Modules\Tasks\Providers\TasksModuleServiceProvider;
use App\Modules\Webinars\Presets\WebinarsPresetContributor;
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


    /*
    |--------------------------------------------------------------------------
    | Dashboard Layout
    |--------------------------------------------------------------------------
    |
    | Dashboard panels are selected by product slot, then by enabled modules.
    | Presets/clients may reorder these lists without adding database-owned
    | dashboard layout state. Actionable panels should stay above context.
    |
    */

    'dashboard' => [
        'slots' => [
            'immediate_work' => [
                'max' => 2,
                'hide_when_empty' => false,
                'panels' => [
                    'tasks.today',
                    'inbound_messaging.replies',
                    'documents.review',
                    'scheduling.today',
                ],
            ],

            'context' => [
                'max' => 2,
                'hide_when_empty' => true,
                'panels' => [
                    'webinars.activity',
                    'campaigns.movement',
                    'broadcasts.recent',
                    'portal.activity',
                ],
            ],
        ],

        'presets' => [
            'crm_basic' => [
                'slots' => [
                    'immediate_work' => [
                        'max' => 2,
                        'panels' => [
                            'tasks.today',
                            'inbound_messaging.replies',
                        ],
                    ],
                    'context' => [
                        'max' => 0,
                        'panels' => [],
                    ],
                ],
            ],

            'webinar_crm' => [
                'slots' => [
                    'immediate_work' => [
                        'max' => 2,
                        'panels' => [
                            'tasks.today',
                            'inbound_messaging.replies',
                        ],
                    ],
                    'context' => [
                        'max' => 2,
                        'panels' => [
                            'webinars.activity',
                            'campaigns.movement',
                            'broadcasts.recent',
                        ],
                    ],
                ],
            ],

            'messaging_first' => [
                'slots' => [
                    'immediate_work' => [
                        'max' => 2,
                        'panels' => [
                            'inbound_messaging.replies',
                            'tasks.today',
                        ],
                        'priorities' => [
                            'inbound_messaging.replies' => 130,
                            'tasks.today' => 100,
                        ],
                    ],
                    'context' => [
                        'max' => 2,
                        'panels' => [
                            'broadcasts.recent',
                            'campaigns.movement',
                            'webinars.activity',
                        ],
                    ],
                ],
            ],
        ],
    ],


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
            'panel' => 'bg-slate-100/50 border-slate-300',
            'panel_focus' => '!bg-slate-200/80 ring-2 ring-slate-500',
            'item' => 'bg-slate-100/80 ring-slate-300',
            'item_focus' => '!bg-slate-200 ring-slate-500',
            'jump' => 'bg-slate-100/50 ring-slate-300 hover:bg-slate-200/50 hover:ring-slate-400 focus:ring-slate-400',
            'badge' => 'bg-slate-200 text-slate-800 ring-slate-300',
            'nav' => 'hover:bg-slate-100 hover:text-slate-950 focus-visible:ring-slate-300',
            'rail' => 'border-l-slate-300',
            'text' => 'text-slate-800',
        ],
        'sky' => [
            'panel' => 'bg-sky-50/50 border-sky-200',
            'panel_focus' => '!bg-sky-100/90 ring-2 ring-sky-400',
            'item' => 'bg-sky-50/80 ring-sky-200',
            'item_focus' => '!bg-sky-100/90 ring-sky-400',
            'jump' => 'bg-sky-50/50 ring-sky-200 hover:bg-sky-100/50 hover:ring-sky-300 focus:ring-sky-300',
            'badge' => 'bg-sky-100 text-sky-800 ring-sky-300',
            'nav' => 'hover:bg-sky-100/70 hover:text-sky-900 focus-visible:ring-sky-300',
            'rail' => 'border-l-sky-300',
            'text' => 'text-sky-800',
        ],
        'blue' => [
            'panel' => 'bg-blue-50/50 border-blue-200',
            'panel_focus' => '!bg-blue-100/90 ring-2 ring-blue-400',
            'item' => 'bg-blue-50/80 ring-blue-200',
            'item_focus' => '!bg-blue-100/90 ring-blue-400',
            'jump' => 'bg-blue-50/50 ring-blue-200 hover:bg-blue-100/50 hover:ring-blue-300 focus:ring-blue-300',
            'badge' => 'bg-blue-100 text-blue-800 ring-blue-300',
            'nav' => 'hover:bg-blue-100/70 hover:text-blue-900 focus-visible:ring-blue-300',
            'rail' => 'border-l-blue-300',
            'text' => 'text-blue-800',
        ],
        'indigo' => [
            'panel' => 'bg-indigo-50/50 border-indigo-200',
            'panel_focus' => '!bg-indigo-100/90 ring-2 ring-indigo-400',
            'item' => 'bg-indigo-50/80 ring-indigo-200',
            'item_focus' => '!bg-indigo-100/90 ring-indigo-400',
            'jump' => 'bg-indigo-50/50 ring-indigo-200 hover:bg-indigo-100/50 hover:ring-indigo-300 focus:ring-indigo-300',
            'badge' => 'bg-indigo-100 text-indigo-800 ring-indigo-300',
            'nav' => 'hover:bg-indigo-100/70 hover:text-indigo-900 focus-visible:ring-indigo-300',
            'rail' => 'border-l-indigo-300',
            'text' => 'text-indigo-800',
        ],
        'violet' => [
            'panel' => 'bg-violet-50/50 border-violet-200',
            'panel_focus' => '!bg-violet-100/90 ring-2 ring-violet-400',
            'item' => 'bg-violet-50/80 ring-violet-200',
            'item_focus' => '!bg-violet-100/90 ring-violet-400',
            'jump' => 'bg-violet-50/50 ring-violet-200 hover:bg-violet-100/50 hover:ring-violet-300 focus:ring-violet-300',
            'badge' => 'bg-violet-100 text-violet-800 ring-violet-300',
            'nav' => 'hover:bg-violet-100/70 hover:text-violet-900 focus-visible:ring-violet-300',
            'rail' => 'border-l-violet-300',
            'text' => 'text-violet-800',
        ],
        'teal' => [
            'panel' => 'bg-teal-50/50 border-teal-200',
            'panel_focus' => '!bg-teal-100/90 ring-2 ring-teal-400',
            'item' => 'bg-teal-50/80 ring-teal-200',
            'item_focus' => '!bg-teal-100/90 ring-teal-400',
            'jump' => 'bg-teal-50/50 ring-teal-200 hover:bg-teal-100/50 hover:ring-teal-300 focus:ring-teal-300',
            'badge' => 'bg-teal-100 text-teal-800 ring-teal-300',
            'nav' => 'hover:bg-teal-100/70 hover:text-teal-900 focus-visible:ring-teal-300',
            'rail' => 'border-l-teal-300',
            'text' => 'text-teal-800',
        ],
        'cyan' => [
            'panel' => 'bg-cyan-50/50 border-cyan-200',
            'panel_focus' => '!bg-cyan-100/90 ring-2 ring-cyan-400',
            'item' => 'bg-cyan-50/80 ring-cyan-200',
            'item_focus' => '!bg-cyan-100/90 ring-cyan-400',
            'jump' => 'bg-cyan-50/50 ring-cyan-200 hover:bg-cyan-100/50 hover:ring-cyan-300 focus:ring-cyan-300',
            'badge' => 'bg-cyan-100 text-cyan-800 ring-cyan-300',
            'nav' => 'hover:bg-cyan-100/70 hover:text-cyan-900 focus-visible:ring-cyan-300',
            'rail' => 'border-l-cyan-300',
            'text' => 'text-cyan-800',
        ],
        'fuchsia' => [
            'panel' => 'bg-fuchsia-50/50 border-fuchsia-200',
            'panel_focus' => '!bg-fuchsia-100/90 ring-2 ring-fuchsia-400',
            'item' => 'bg-fuchsia-50/80 ring-fuchsia-200',
            'item_focus' => '!bg-fuchsia-100/90 ring-fuchsia-400',
            'jump' => 'bg-fuchsia-50/50 ring-fuchsia-200 hover:bg-fuchsia-100/50 hover:ring-fuchsia-300 focus:ring-fuchsia-300',
            'badge' => 'bg-fuchsia-100 text-fuchsia-800 ring-fuchsia-300',
            'nav' => 'hover:bg-fuchsia-100/70 hover:text-fuchsia-900 focus-visible:ring-fuchsia-300',
            'rail' => 'border-l-fuchsia-300',
            'text' => 'text-fuchsia-800',
        ],
        'stone' => [
            'panel' => 'bg-stone-100/50 border-stone-300',
            'panel_focus' => '!bg-stone-200/80 ring-2 ring-stone-500',
            'item' => 'bg-stone-100/80 ring-stone-300',
            'item_focus' => '!bg-stone-200 ring-stone-500',
            'jump' => 'bg-stone-100/50 ring-stone-300 hover:bg-stone-200/50 hover:ring-stone-400 focus:ring-stone-400',
            'badge' => 'bg-stone-200 text-stone-800 ring-stone-300',
            'nav' => 'hover:bg-stone-100 hover:text-stone-950 focus-visible:ring-stone-300',
            'rail' => 'border-l-stone-300',
            'text' => 'text-stone-800',
        ],
        'emerald' => [
            'panel' => 'bg-emerald-50/50 border-emerald-200',
            'panel_focus' => '!bg-emerald-100/90 ring-2 ring-emerald-400',
            'item' => 'bg-emerald-50/80 ring-emerald-200',
            'item_focus' => '!bg-emerald-100/90 ring-emerald-400',
            'jump' => 'bg-emerald-50/50 ring-emerald-200 hover:bg-emerald-100/50 hover:ring-emerald-300 focus:ring-emerald-300',
            'badge' => 'bg-emerald-100 text-emerald-800 ring-emerald-300',
            'nav' => 'hover:bg-emerald-100/70 hover:text-emerald-900 focus-visible:ring-emerald-300',
            'rail' => 'border-l-emerald-300',
            'text' => 'text-emerald-800',
        ],
        'lime' => [
            'panel' => 'bg-lime-50/50 border-lime-200',
            'panel_focus' => '!bg-lime-100/90 ring-2 ring-lime-400',
            'item' => 'bg-lime-50/80 ring-lime-200',
            'item_focus' => '!bg-lime-100/90 ring-lime-400',
            'jump' => 'bg-lime-50/50 ring-lime-200 hover:bg-lime-100/50 hover:ring-lime-300 focus:ring-lime-300',
            'badge' => 'bg-lime-100 text-lime-800 ring-lime-300',
            'nav' => 'hover:bg-lime-100/70 hover:text-lime-900 focus-visible:ring-lime-300',
            'rail' => 'border-l-lime-300',
            'text' => 'text-lime-800',
        ],
        'amber' => [
            'panel' => 'bg-amber-50/50 border-amber-200',
            'panel_focus' => '!bg-amber-100/90 ring-2 ring-amber-400',
            'item' => 'bg-amber-50/80 ring-amber-200',
            'item_focus' => '!bg-amber-100/90 ring-amber-400',
            'jump' => 'bg-amber-50/50 ring-amber-200 hover:bg-amber-100/50 hover:ring-amber-300 focus:ring-amber-300',
            'badge' => 'bg-amber-100 text-amber-800 ring-amber-300',
            'nav' => 'hover:bg-amber-100/70 hover:text-amber-900 focus-visible:ring-amber-300',
            'rail' => 'border-l-amber-300',
            'text' => 'text-amber-800',
        ],
        'orange' => [
            'panel' => 'bg-orange-50/50 border-orange-200',
            'panel_focus' => '!bg-orange-100/90 ring-2 ring-orange-400',
            'item' => 'bg-orange-50/80 ring-orange-200',
            'item_focus' => '!bg-orange-100/90 ring-orange-400',
            'jump' => 'bg-orange-50/50 ring-orange-200 hover:bg-orange-100/50 hover:ring-orange-300 focus:ring-orange-300',
            'badge' => 'bg-orange-100 text-orange-800 ring-orange-300',
            'nav' => 'hover:bg-orange-100/70 hover:text-orange-900 focus-visible:ring-orange-300',
            'rail' => 'border-l-orange-300',
            'text' => 'text-orange-800',
        ],
        'rose' => [
            'panel' => 'bg-rose-50/50 border-rose-200',
            'panel_focus' => '!bg-rose-100/90 ring-2 ring-rose-400',
            'item' => 'bg-rose-50/80 ring-rose-200',
            'item_focus' => '!bg-rose-100/90 ring-rose-400',
            'jump' => 'bg-rose-50/50 ring-rose-200 hover:bg-rose-100/50 hover:ring-rose-300 focus:ring-rose-300',
            'badge' => 'bg-rose-100 text-rose-800 ring-rose-300',
            'nav' => 'hover:bg-rose-100/70 hover:text-rose-900 focus-visible:ring-rose-300',
            'rail' => 'border-l-rose-300',
            'text' => 'text-rose-800',
        ],
        'pink' => [
            'panel' => 'bg-pink-50/50 border-pink-200',
            'panel_focus' => '!bg-pink-100/90 ring-2 ring-pink-400',
            'item' => 'bg-pink-50/80 ring-pink-200',
            'item_focus' => '!bg-pink-100/90 ring-pink-400',
            'jump' => 'bg-pink-50/50 ring-pink-200 hover:bg-pink-100/50 hover:ring-pink-300 focus:ring-pink-300',
            'badge' => 'bg-pink-100 text-pink-800 ring-pink-300',
            'nav' => 'hover:bg-pink-100/70 hover:text-pink-900 focus-visible:ring-pink-300',
            'rail' => 'border-l-pink-300',
            'text' => 'text-pink-800',
        ],
        'purple' => [
            'panel' => 'bg-purple-50/50 border-purple-200',
            'panel_focus' => '!bg-purple-100/90 ring-2 ring-purple-400',
            'item' => 'bg-purple-50/80 ring-purple-200',
            'item_focus' => '!bg-purple-100/90 ring-purple-400',
            'jump' => 'bg-purple-50/50 ring-purple-200 hover:bg-purple-100/50 hover:ring-purple-300 focus:ring-purple-300',
            'badge' => 'bg-purple-100 text-purple-800 ring-purple-300',
            'nav' => 'hover:bg-purple-100/70 hover:text-purple-900 focus-visible:ring-purple-300',
            'rail' => 'border-l-purple-300',
            'text' => 'text-purple-800',
        ],
        'zinc' => [
            'panel' => 'bg-zinc-100/50 border-zinc-300',
            'panel_focus' => '!bg-zinc-200/80 ring-2 ring-zinc-500',
            'item' => 'bg-zinc-100/80 ring-zinc-300',
            'item_focus' => '!bg-zinc-200 ring-zinc-500',
            'jump' => 'bg-zinc-100/50 ring-zinc-300 hover:bg-zinc-200/50 hover:ring-zinc-400 focus:ring-zinc-400',
            'badge' => 'bg-zinc-200 text-zinc-800 ring-zinc-300',
            'nav' => 'hover:bg-zinc-100 hover:text-zinc-950 focus-visible:ring-zinc-300',
            'rail' => 'border-l-zinc-300',
            'text' => 'text-zinc-800',
        ],
    ],

    'modules' => [

        'dashboard' => [
            'name' => 'Dashboard',
            'ui' => [
                'tone' => 'zinc',
            ],
            'nav' => [
                'label' => 'Dashboard',
                'route' => 'crm.index',
                'priority' => 10,
            ],
            'always_on' => true,
            'depends_on' => [],
            'requires_provider' => false,
            'providers' => [],
        ],

        'core' => [
            'name' => 'Core CRM',
            'ui' => [
                'tone' => 'slate',
            ],
            'nav' => [
                [
                    'label_config' => 'contacts.labels.plural',
                    'route' => 'crm.contacts.index',
                    'priority' => 20,
                    'class' => 'capitalize',
                ],
            ],
            'always_on' => true,
            'depends_on' => [],
            'preset_contributors' => [
                CorePresetContributor::class,
                ClientPresetContributor::class,
            ],
            'providers' => [
                CoreModuleServiceProvider::class,
            ],
        ],

        'messaging' => [
            'name' => 'Messaging',
            'ui' => [
                'tone' => 'sky',
            ],
            'nav' => [
                'label' => 'Message Templates',
                'route' => 'crm.messaging.message-templates.index',
                'priority' => 80,
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
                'tone' => 'emerald',
            ],
            'nav' => [
                'label' => 'Tasks',
                'route' => 'crm.tasks.index',
                'priority' => 25,
            ],
            'depends_on' => ['core'],
            'preset_contributors' => [
                TasksPresetContributor::class,
            ],
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
                'tone' => 'pink',
            ],
            'depends_on' => ['core'],
            'providers' => [
                PortalModuleServiceProvider::class,
            ],
        ],

        'forms' => [
            'name' => 'Forms',
            'ui' => [
                'tone' => 'teal',
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
            'nav' => [
                'label' => 'Routes',
                'route' => 'crm.flow-routes.index',
                'priority' => 90,
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
                'tone' => 'purple',
            ],
            'nav' => [
                'label' => 'Broadcasts',
                'route' => 'crm.broadcasts.index',
                'priority' => 60,
            ],
            'depends_on' => ['core', 'messaging'],
            'providers' => [
                BroadcastsModuleServiceProvider::class,
            ],
        ],

        'webinars' => [
            'name' => 'Webinars',
            'ui' => [
                'tone' => 'stone',
            ],
            'nav' => [
                'label' => 'Webinars',
                'route' => 'crm.webinar-series.index',
                'priority' => 70,
            ],
            'depends_on' => ['core', 'messaging'],
            'preset_contributors' => [
                WebinarsPresetContributor::class,
            ],
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
                'tone' => 'violet',
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
