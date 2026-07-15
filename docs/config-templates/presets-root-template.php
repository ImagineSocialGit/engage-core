<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Root Presets Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/presets.php
    | client/{client-key}/config/presets.php, if client package composition differs
    |
    | This file composes preset packages and tells `php artisan presets:sync`
    | which module preset groups to sync.
    |
    | Core should keep a small generic package surface. Rich vertical/client
    | packages belong in `client/{client-key}/config/presets.php`.
    |
    | If `client.preset` selects a client-specific key such as `mortgage`, that
    | package must exist in the effective merged `presets.packages` config.
    | Do not add vertical-rich packages to Core merely to silence a missing-package
    | setup-validation error.
    |
    | Global preset sync remains dependency-safe across the actual synced domains
    | and supporting catalogs:
    |
    | 1. contact_statuses
    | 2. tasks
    | 3. messaging template presets
    | 4. webinar schedule profiles
    | 5. campaigns
    | 6. FlowRoute capabilities
    | 7. flow_routes
    |
    | Campaigns must sync before FlowRoutes because FlowRoutes may reference
    | campaign_key values in enroll_campaign points.
    |
    | Modules with no preset sync, such as Commerce or Location foundations,
    | should not appear in sync_order until they own DB-backed preset sync.
    */

    /*
    |--------------------------------------------------------------------------
    | Module-first preset contribution architecture
    |--------------------------------------------------------------------------
    |
    | Preset contributions are module-first and explicitly registered.
    | Each contributor may expose zero or more preset domains, for example:
    |
    | config/presets/modules/core/contact-statuses.php
    | config/presets/modules/tasks/tasks.php
    |
    | config/presets/modules/webinars/contact-statuses.php
    | config/presets/modules/webinars/tasks.php
    | config/presets/modules/webinars/campaigns.php
    | config/presets/modules/webinars/flow-routes.php
    |
    | Do not create empty symmetry files. A contributor should expose only the
    | domains it genuinely contributes.
    |
    | The shared infrastructure is:
    |
    | PresetContributionRegistry
    |     -> all available contributed groups/definitions by domain
    |
    | PresetPackageResolver
    |     -> selected package, selected groups, and package module composition/requirements
    |
    | PresetCompositionResolver
    |     -> ResolvedPresetDomain for one selected package/domain
    |
    | Domain sync action
    |     -> persists exactly the resolved selected definitions
    |
    | Keep these concepts separate:
    |
    | - module availability: runtime capability exists for the client;
    | - preset contribution availability: installed contributors expose definitions;
    | - package selection: selected groups determine what is installed/synced;
    | - runtime activation: DB-owned selections/bindings decide what actually runs.
    |
    | Installed contributors may expose preset definitions even when their runtime
    | module is disabled. Enabling a module must not silently activate every preset
    | it contributes.
    |
    | Group keys are composition-only and are not durable persisted ownership.
    | Durable preset ownership belongs to contributor identity plus stable
    | definition key.
    |
    */

    'default_package' => 'basic',

    /*
    |--------------------------------------------------------------------------
    | Setup validation contract
    |--------------------------------------------------------------------------
    |
    | Preset packages are validated through the shared setup-validation manager.
    | Owning modules contribute validators for their own config/preset shapes and
    | cross-references. Validation findings use one reusable structured shape and
    | may be consumed by CLI commands now and authoring/readiness UI later.
    |
    | Hard errors represent unsafe or impossible intended runtime behavior.
    | Warnings represent dormant, unused, discouraged, or surprising-but-safe
    | configuration.
    |
    | Do not persist validation runs/findings unless a concrete operator workflow
    | proves historical validation state is needed.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Package module requirements
    |--------------------------------------------------------------------------
    |
    | packages.*.modules.enabled declares modules that must be runtime-enabled
    | for that package to be valid. It is not the runtime module source of truth.
    |
    | Runtime module authority belongs to config('modules.enabled'), normally
    | supplied by client/{client-key}/config/modules.php.
    |
    */

    'packages' => [
        'basic' => [
            'name' => 'Basic',
            'description' => 'Core CRM, Tasks, and Workflow.',
            'modules' => [
                'enabled' => [
                    'tasks',
                    'workflow',
                ],
            ],
            'groups' => [
                'contact_statuses' => [
                    'default',
                ],
                'tasks' => [
                    'default',
                ],
                'campaigns' => [],
                'flow_routes' => [],
            ],
        ],

        'messaging' => [
            'name' => 'Messaging',
            'description' => 'Basic plus Messaging, Inbound Messaging, Internal Notifications, and Broadcasts.',
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
            'groups' => [
                'contact_statuses' => [
                    'default',
                ],
                'tasks' => [
                    'default',
                ],
                'campaigns' => [],
                'flow_routes' => [],
            ],
        ],

        'automated_messaging' => [
            'name' => 'Automated Messaging',
            'description' => 'Messaging plus FlowRoutes. Campaigns remain opt-in.',
            'modules' => [
                'enabled' => [
                    'tasks',
                    'workflow',
                    'messaging',
                    'inbound_messaging',
                    'internal_notifications',
                    'broadcasts',
                    'flow_routes',
                ],
            ],
            'groups' => [
                'contact_statuses' => [
                    'default',
                ],
                'tasks' => [
                    'default',
                ],
                'campaigns' => [],
                'flow_routes' => [],
            ],
        ],
    ],

];
