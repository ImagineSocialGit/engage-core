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
    | definitions, or ContactStatus keys through point definitions.
    |
    | FlowRoute preset sync assumes dependencies were synced first:
    | contact_statuses -> tasks -> campaigns -> flow_routes.
    | 
    | Preset sync creates available FlowRoute definitions and may create default selected trigger bindings.
    | Runtime execution should resolve selected routes through FlowRouteTriggerBinding, not by running every active matching route.
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

        // Example only. Do not activate this preset until Phase 5 implements
        // task.completed event_wait resume with specific route progress, plan item,
        // progress item, created Task identity, template identity, and subject-context
        // correlation. Broad contact-only task.completed waits are unsafe.

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
