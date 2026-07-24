<?php

/*
|--------------------------------------------------------------------------
| FlowRoute preset template
|--------------------------------------------------------------------------
|
| Author only durable business intent. Preset normalization derives:
|
| - the FlowRoute key from each definitions map key;
| - manual, contact-status, or automation-event trigger type;
| - each FlowRoutePoint key from its points map key;
| - point capability from point type;
| - point sort order, start point, and next-point chain from map order;
| - point source_version from the containing Route;
| - empty settings and registered Point-definition defaults.
|
| Removed verbose fields are invalid. Do not author route key/trigger/meta or
| point key/capability_key/sort_order/is_start/next_point_key/settings/
| source_version/meta.
|
| A Route is manual when neither contact_status_key nor event_key is present.
| Define at most one of those trigger keys.
|
| Keep module-owned executable values inside each Point's definition object.
| Point-definition schemas and semantic reference checks come from the module
| that owns that Point type.
|
*/

return [
    'groups' => [
        'example_default' => [
            'webinar_attended_follow_up',
            'attempting_contact_follow_up',
            'manual_review_route',
        ],
    ],

    'definitions' => [
        'webinar_attended_follow_up' => [
            'event_key' => 'webinar.attended',
            'name' => 'Webinar Attended Follow-Up',
            'description' => 'Enroll webinar attendees in the configured follow-up Campaign.',
            'source_version' => '2026_07_example_1',
            'owner_group' => 'webinars',
            'category' => 'webinar',
            'role' => 'campaign_enrollment',
            'points' => [
                'enroll_attended_campaign' => [
                    'type' => 'enroll_campaign',
                    'name' => 'Enroll attended follow-up',
                    'definition' => [
                        'campaign_key' => 'webinar_attended_nurture',
                    ],
                ],
            ],
        ],

        'attempting_contact_follow_up' => [
            'contact_status_key' => 'attempting_contact',
            'name' => 'Attempting Contact Follow-Up',
            'description' => 'Create an outreach task, wait one week, then create a follow-up task.',
            'source_version' => '2026_07_example_1',
            'owner_group' => 'sales',
            'category' => 'contact_follow_up',
            'role' => 'status_follow_up',
            'points' => [
                'create_initial_task' => [
                    'type' => 'create_task',
                    'definition' => [
                        'task_template_key' => 'contact.initial_outreach',
                    ],
                ],
                'wait_one_week' => [
                    'type' => 'wait',
                    'definition' => [
                        'weeks' => 1,
                    ],
                    'cancel_conditions' => [
                        [
                            'type' => 'contact_status_changed',
                        ],
                    ],
                ],
                'create_follow_up_task' => [
                    'type' => 'create_task',
                    'definition' => [
                        'task_template_key' => 'contact.follow_up',
                    ],
                ],
            ],
        ],

        'manual_review_route' => [
            'name' => 'Manual Review Route',
            'description' => 'A Route that is available for an explicit manual start.',
            'source_version' => '2026_07_example_1',
            'points' => [
                'wait_for_review_completed' => [
                    'type' => 'event_wait',
                    'definition' => [
                        'event_key' => 'review.completed',
                    ],
                ],
                'mark_reviewed' => [
                    'type' => 'change_status',
                    'definition' => [
                        'contact_status_key' => 'reviewed',
                    ],
                ],
            ],
        ],
    ],
];