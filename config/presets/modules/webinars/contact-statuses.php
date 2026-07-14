<?php

return [
    'groups' => [
        'webinar_default' => [
            'registered',
            'attended_webinar',
            'missed_webinar',
        ],
    ],

    'definitions' => [
        'registered' => [
            'key' => 'registered',
            'name' => 'Registered',
            'description' => 'Contact registered for a webinar, event, class, or similar conversion step.',
            'category' => 'webinar',
            'sort_order' => 200,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [
                'intent_level' => 'medium',
                'automation_role' => 'wait_for_outcome',
            ],
        ],

        'attended_webinar' => [
            'key' => 'attended_webinar',
            'name' => 'Attended Webinar',
            'description' => 'Contact attended a webinar and is eligible for attended follow-up.',
            'category' => 'webinar',
            'sort_order' => 210,
            'is_active' => true,
            'source_version' => 1,
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
            'sort_order' => 220,
            'is_active' => true,
            'source_version' => 1,
            'meta' => [
                'intent_level' => 'medium',
                'automation_role' => 'replay_or_reschedule_follow_up',
            ],
        ],
    ],
];