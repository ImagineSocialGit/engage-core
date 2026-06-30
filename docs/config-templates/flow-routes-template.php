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
    | Producer modules must not import FlowRoutes.
    | Producer modules emit AutomationEventRecorded.
    | FlowRoutes listens and maps automation events internally.
    |
    | FlowRoute presets may reference DB-owned Campaigns, Tasks, Messaging
    | definitions, or ContactStatus keys through point definitions.
    |
    | FlowRoute preset sync assumes dependencies were synced first:
    | contact_statuses -> tasks -> campaigns -> flow_routes.
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

        'task_completed_resume_example' => [
            'key' => 'task_completed_resume_example',
            'name' => 'Task Completed Resume Example',
            'description' => 'Example route showing how event_wait can resume from generic task.completed automation events.',
            'status' => 'inactive',
            'is_active' => false,
            'source_version' => 1,

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