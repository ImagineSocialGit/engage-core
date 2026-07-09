<?php

return [
    'groups' => [
        'general_default' => ['new', 'engaged', 'requires_action', 'inactive'],
        'webinar_default' => ['new', 'registered', 'attended_webinar', 'missed_webinar', 'engaged', 'requires_action', 'inactive'],
        'mortgage_default' => ['new', 'registered', 'attended_webinar', 'missed_webinar', 'engaged', 'requires_action', 'application_started', 'prospect', 'in_process', 'closed', 'inactive', 'not_interested'],
        'pet_service_default' => ['new', 'engaged', 'requires_action', 'inactive'],
        'musician_fan_default' => ['new', 'engaged', 'requires_action', 'inactive'],
    ],

    'definitions' => [
        'new' => [
            'key' => 'new',
            'name' => 'New',
            'description' => 'Default starting state for a contact that exists but has not had enough meaningful interaction yet.',
            'category' => 'default',
            'sort_order' => 10,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'unknown',
                'automation_role' => 'starting_state',
            ],
        ],

        'registered' => [
            'key' => 'registered',
            'name' => 'Registered',
            'description' => 'Contact registered for a webinar, event, class, or similar conversion step.',
            'category' => 'webinar',
            'sort_order' => 15,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'medium',
                'automation_role' => 'wait_for_outcome',
            ],
        ],

        'engaged' => [
            'key' => 'engaged',
            'name' => 'Engaged',
            'description' => 'Contact has shown meaningful interest or interaction.',
            'category' => 'default',
            'sort_order' => 20,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'medium',
                'automation_role' => 'nurture_or_follow_up',
            ],
        ],

        'attended_webinar' => [
            'key' => 'attended_webinar',
            'name' => 'Attended Webinar',
            'description' => 'Contact attended a webinar and is eligible for attended follow-up.',
            'category' => 'webinar',
            'sort_order' => 25,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'medium_high',
                'automation_role' => 'attended_follow_up',
            ],
        ],

        'missed_webinar' => [
            'key' => 'missed_webinar',
            'name' => 'Missed Webinar',
            'description' => 'Contact registered for a webinar but did not attend.',
            'category' => 'webinar',
            'sort_order' => 26,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'medium',
                'automation_role' => 'replay_or_reschedule_follow_up',
            ],
        ],

        'requires_action' => [
            'key' => 'requires_action',
            'name' => 'Requires Action',
            'description' => 'Contact needs human attention, review, or follow-up.',
            'category' => 'default',
            'sort_order' => 30,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'high_or_unclear',
                'automation_role' => 'task_or_internal_notification',
            ],
        ],

        'application_started' => [
            'key' => 'application_started',
            'name' => 'Application Started',
            'description' => 'Contact started an application, intake, or similar high-intent process.',
            'category' => 'mortgage',
            'sort_order' => 40,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'high',
                'automation_role' => 'sales_or_ops_follow_up',
            ],
        ],

        'prospect' => [
            'key' => 'prospect',
            'name' => 'Prospect',
            'description' => 'Contact has moved from webinar follow-up into the application/intake prospect path.',
            'category' => 'mortgage',
            'sort_order' => 45,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'high',
                'automation_role' => 'cancel_webinar_nurture_and_create_prospect_task',
            ],
        ],

        'in_process' => [
            'key' => 'in_process',
            'name' => 'In Process',
            'description' => 'Contact is actively being worked by the business or team.',
            'category' => 'mortgage',
            'sort_order' => 50,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'high',
                'automation_role' => 'active_pipeline',
            ],
        ],

        'closed' => [
            'key' => 'closed',
            'name' => 'Closed',
            'description' => 'Contact reached a successful or completed outcome.',
            'category' => 'mortgage',
            'sort_order' => 80,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'completed',
                'automation_role' => 'post_close_or_noop',
            ],
        ],

        'inactive' => [
            'key' => 'inactive',
            'name' => 'Inactive',
            'description' => 'Contact is not currently in an active follow-up path.',
            'category' => 'default',
            'sort_order' => 90,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'low',
                'automation_role' => 'quiet_or_noop',
            ],
        ],

        'not_interested' => [
            'key' => 'not_interested',
            'name' => 'Not Interested',
            'description' => 'Contact explicitly indicated they are not interested in the opportunity.',
            'category' => 'mortgage',
            'sort_order' => 95,
            'is_active' => true,
            'source_version' => '2026_07_phase_6c_1',
            'meta' => [
                'intent_level' => 'none',
                'automation_role' => 'stop_sales_follow_up',
            ],
        ],
    ],
];
