
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FlowRoute Presets Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/presets/flow-routes.php
    | client/{client-key}/config/presets/flow-routes.php, if client override exists
    |
    | FlowRoutes own automation/control flow.
    | Client/operator UI may call this Route Management or Routes, but config and code should keep the FlowRoutes domain name precise.
    | Producer modules must not import FlowRoutes.
    | Producer modules emit AutomationEventRecorded.
    | FlowRoutes listens and maps automation events internally.
    |
    | FlowRoute presets may reference DB-owned Campaigns, Tasks, Messaging
    | definitions, ContactStatus keys, and durable FlowRoute capabilities through
    | point definitions. Capability references should use stable keys when the
    | authoring/runtime path supports them.
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
    | FlowRoutes owns validation of route preset shape, trigger shape, point type,
    | registered point-handler availability, capability references, route graph,
    | subject support, route-instance/snapshot assumptions, and point-specific
    | references.
    |
    | Cross-module references must be checked through public/runtime truths:
    | - Tasks validates Task-template availability.
    | - Campaigns validates Campaign availability.
    | - Messaging validates message/template/field context.
    | - Module availability comes from the canonical module manager/provider state.
    |
    | A selected preset that contains a point whose required handler/module cannot
    | execute is a hard validation error, not merely a warning.
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
            'status' => 'active',
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
                    'name' => 'Enroll Webinar Attended Nurture',
                    'description' => 'Enroll the contact into the attended webinar nurture campaign.',
                    'default_definition' => [
                        'campaign_key' => 'webinar_attended_nurture',
                        'on_already_enrolled' => 'skipped',
                        'payload' => [],
                        'meta' => [
                            'source' => 'flow_route',
                        ],
                    ],
                    'sort_order' => 10,
                    'is_start' => true,
                    'next_point_key' => null,
                    'conditions' => [],
                    'meta' => [],
                ],
            ],
        ],

        'webinar_missed_to_nurture' => [
            'key' => 'webinar_missed_to_nurture',
            'name' => 'Webinar Missed Follow-Up',
            'description' => 'Starts the missed webinar nurture campaign when a contact-scoped webinar.missed automation event is recorded.',
            'status' => 'active',
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
                    'name' => 'Enroll Webinar Missed Nurture',
                    'description' => 'Enroll the contact into the missed webinar nurture campaign.',
                    'default_definition' => [
                        'campaign_key' => 'webinar_missed_nurture',
                        'on_already_enrolled' => 'skipped',
                        'payload' => [],
                        'meta' => [
                            'source' => 'flow_route',
                        ],
                    ],
                    'sort_order' => 10,
                    'is_start' => true,
                    'next_point_key' => null,
                    'conditions' => [],
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
        // correlation such as task.task_template_key plus flow_route_progress_id,
        // flow_route_plan_item_id, or flow_route_progress_item_id.

        'task_completed_resume_example' => [
            'key' => 'task_completed_resume_example',
            'name' => 'Task Completed Resume Example',
            'description' => 'Example route showing how event_wait can resume from generic task.completed automation events.',
            'status' => 'inactive',
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
                    'name' => 'Wait for Task Completed',
                    'description' => 'Pause until a matching task.completed automation event is recorded.',
                    'default_definition' => [
                        'event_key' => 'task.completed',
                        'correlation' => [
                            'task.task_template_key' => 'example.follow_up',
                            'task.flow_route_progress_id' => '{flow_route_progress.id}',
                        ],
                        'timeout' => null,
                    ],
                    'sort_order' => 10,
                    'is_start' => true,
                    'next_point_key' => null,
                    'conditions' => [],
                    'meta' => [],
                ],
            ],
        ],

    ],

];


