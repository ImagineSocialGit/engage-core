<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FlowRoute Presets Template
    |--------------------------------------------------------------------------
    |
    | Example module-first file paths:
    | config/presets/modules/webinars/flow-routes.php
    | config/presets/modules/{contributor-module}/flow-routes.php
    | client/{client-key}/config/presets/modules/{contributor-module}/flow-routes.php
    |
    | Preset contributions are explicitly registered by module contributors and
    | aggregated by the shared preset registry. FlowRoute sync consumes a
    | ResolvedPresetDomain and does not depend directly on contributor directory
    | structure.
    |
    | Module availability, preset contribution availability, package selection,
    | and runtime trigger binding remain separate decisions.
    |
    | FlowRoutes own automation/control flow.
    | Client/operator UI may call this Route Management or Routes, but config and code should keep the FlowRoutes domain name precise.
    | Producer modules must not import FlowRoutes.
    | Producer modules emit AutomationEventRecorded.
    | FlowRoutes listens and maps automation events internally.
    |
    | FlowRoute presets may reference DB-owned Campaigns, Tasks, Messaging
    | definitions, ContactStatus keys, and durable FlowRoute capabilities through
    | concrete FlowRoutePoint definitions.
    |
    | FlowRoutePoint directly owns its type, name, description, definition,
    | settings, cancel conditions, route ordering, and route-local durable key.
    | There is no separate global Point model/table/template layer.
    |
    | Point-specific extension ownership is contributor-driven:
    | - FlowRoutes owns native orchestration Point definitions and Route structure.
    | - Tasks owns create_task schema/semantic validation and automatic Task action.
    | - Messaging owns send_message schema/semantic validation and message action.
    | - Campaigns owns enroll_campaign/cancel_campaign schemas, validation, and actions.
    | - Authorable modules own their Point-specific fields, request rules, warnings,
    |   definition building, generated names, and summaries through the shared
    |   automation authoring registry.
    |
    | `create_task` is an automatic repeated action and requires task_template_key.
    | Do not author a title-only automatic Task Point.
    |
    | Capability references should use stable keys when the authoring/runtime path
    | supports them.
    |
    | Global preset sync uses dependency-safe order:
    | contact_statuses -> tasks -> messaging -> webinar schedule profiles
    | -> campaigns -> FlowRoute capabilities -> FlowRoutes.
    |
    | FlowRoute capability sync must run before FlowRoute preset sync when route
    | points reference durable capability keys.
    | 
    | Preset sync creates available FlowRoute definitions and may create default selected trigger bindings.
    | Runtime execution should resolve selected routes through FlowRouteTriggerBinding, not by running every active matching route.
    |
    | FlowRoute.key is durable logical identity. version is definition revision.
    | is_current_version selects the current revision; is_active determines whether
    | that selected revision is enabled.
    |
    | When a new revision becomes current, active/waiting older instances reconcile
    | by durable FlowRoutePoint key into a new route-plan revision. Unmappable
    | current/waiting points are hard conflicts; do not guess, skip, restart, or
    | cancel them automatically.
    */

    /*
    |--------------------------------------------------------------------------
    | Validation expectations
    |--------------------------------------------------------------------------
    |
    | Shared preset-composition validation owns package/group/definition structure,
    | including missing selected groups and duplicate contributed group/definition
    | keys.
    |
    | FlowRoutes owns validation of the Route envelope and orchestration runtime:
    | trigger shape, registered point-handler availability, capability matching,
    | required module availability, route graph integrity, subject support,
    | route-instance/snapshot assumptions, active trigger bindings, and runtime
    | progress/plan consistency.
    |
    | Point-specific definition schemas and semantic/domain-reference validation are
    | contributor-owned through AutomationPointDefinitionRegistry:
    | - FlowRoutes validates native orchestration Points such as wait/change_status.
    | - Tasks validates create_task definitions and TaskTemplate availability.
    | - Campaigns validates Campaign action definitions and Campaign availability.
    | - Messaging validates send_message definition/template context.
    | - Module availability comes from the canonical module manager/provider state.
    |
    | A selected preset that contains a point whose required handler/module cannot
    | execute is a hard validation error, not merely a warning.
    |
    | The executable `flow_routes.preset_definition` contract is closed by
    | default and each Point `type` selects a Point-specific definition schema.
    | Route-level `status`, Point-level `conditions`, and event-wait `timeout`
    | are not preset fields because the current preset DTO/runtime ignores them.
    | Put executable Point behavior inside that Point type's `definition` only.
    |
    */

    'groups' => [
        'webinar_default' => [
            'webinar_attended_to_nurture',
            'webinar_missed_to_nurture',
        ],
    ],

    'definitions' => [

        'webinar_attended_to_nurture' => [
            'key' => 'webinar_attended_to_nurture',
            'name' => 'Webinar Attended Follow-Up',
            'description' => 'Starts the attended nurture campaign when a contact-scoped webinar.attended automation event is recorded.',
            'is_active' => true,
            'source_version' => 1,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'system',

            'trigger' => [
                'type' => 'automation_event',
                'event_key' => 'webinar.attended',
            ],

            'meta' => [
                'category' => 'webinar',
                'default_role' => 'campaign_enrollment',
            ],

            'points' => [
                [
                    'key' => 'enroll_webinar_attended_nurture',
                    'type' => 'enroll_campaign',
                    'capability_key' => 'campaigns.enroll_contact',
                    'name' => 'Enroll Webinar Attended Nurture',
                    'description' => 'Enroll the contact into the attended webinar nurture campaign.',
                    'definition' => [
                        'campaign_key' => 'webinar_attended_nurture',
                        'on_already_enrolled' => 'skipped',
                        'payload' => [],
                        'meta' => [
                            'source' => 'flow_route',
                        ],
                    ],
                    'settings' => [],
                    'cancel_conditions' => [],
                    'sort_order' => 10,
                    'is_start' => true,
                    'next_point_key' => null,
                    'meta' => [],
                ],
            ],
        ],

        'webinar_missed_to_nurture' => [
            'key' => 'webinar_missed_to_nurture',
            'name' => 'Webinar Missed Follow-Up',
            'description' => 'Starts the missed webinar nurture campaign when a contact-scoped webinar.missed automation event is recorded.',
            'is_active' => true,
            'source_version' => 1,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'system',

            'trigger' => [
                'type' => 'automation_event',
                'event_key' => 'webinar.missed',
            ],

            'meta' => [
                'category' => 'webinar',
                'default_role' => 'campaign_enrollment',
            ],

            'points' => [
                [
                    'key' => 'enroll_webinar_missed_nurture',
                    'type' => 'enroll_campaign',
                    'capability_key' => 'campaigns.enroll_contact',
                    'name' => 'Enroll Webinar Missed Nurture',
                    'description' => 'Enroll the contact into the missed webinar nurture campaign.',
                    'definition' => [
                        'campaign_key' => 'webinar_missed_nurture',
                        'on_already_enrolled' => 'skipped',
                        'payload' => [],
                        'meta' => [
                            'source' => 'flow_route',
                        ],
                    ],
                    'settings' => [],
                    'cancel_conditions' => [],
                    'sort_order' => 10,
                    'is_start' => true,
                    'next_point_key' => null,
                    'meta' => [],
                ],
            ],
        ],

        // Example only. task.completed event_wait resume is supported, but broad
        // contact-only task completion waits remain unsafe.
        //
        // If a route creates exactly one Task before the event_wait, FlowRoutes may
        // resume from that specific route-created Task identity.
        //
        // If a route can create multiple Tasks before the event_wait, define explicit
        // correlation using neutral Task event fields that truthfully distinguish the
        // intended Task, such as task.task_template_key. Route-instance identity stays
        // in FlowRoutes-owned created-artifact/correlation state, not task.flow_route_*.

        'task_completed_resume_example' => [
            'key' => 'task_completed_resume_example',
            'name' => 'Task Completed Resume Example',
            'description' => 'Example route showing how event_wait can resume from generic task.completed automation events.',
            'is_active' => false,
            'source_version' => 1,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'system',

            'trigger' => [
                'type' => 'manual',
            ],

            'meta' => [
                'category' => 'tasks',
                'example_only' => true,
            ],

            'points' => [
                [
                    'key' => 'wait_for_task_completed',
                    'type' => 'event_wait',
                    'capability_key' => 'flow_routes.event_wait',
                    'name' => 'Wait for Task Completed',
                    'description' => 'Pause until a matching task.completed automation event is recorded.',
                    'definition' => [
                        'event_key' => 'task.completed',
                        'correlation' => [
                            'task.task_template_key' => 'example.follow_up',
                        ],
                    ],
                    'settings' => [],
                    'cancel_conditions' => [],
                    'sort_order' => 10,
                    'is_start' => true,
                    'next_point_key' => null,
                    'meta' => [],
                ],
            ],
        ],

    ],

];



