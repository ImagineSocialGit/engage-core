<?php

return [
    'groups' => [
        'general_default' => ['new', 'engaged', 'requires_action', 'inactive'],
        'webinar_default' => ['new', 'registered', 'attended_webinar', 'missed_webinar', 'engaged', 'requires_action', 'inactive'],
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

    ],
];

