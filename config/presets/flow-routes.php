<?php

return [
    'groups' => [
        'webinar_default' => [
            'webinar_attended_status_transition',
            'webinar_missed_status_transition',
            'webinar_attended_campaign_enrollment',
            'webinar_missed_campaign_enrollment',
        ],
        'mortgage_default' => [
            'webinar_attended_status_transition',
            'webinar_missed_status_transition',
            'webinar_attended_campaign_enrollment',
            'webinar_missed_campaign_enrollment',
        ],
    ],
    'definitions' => [
        'webinar_attended_status_transition' => [
            'key' => 'webinar_attended_status_transition',
            'trigger' => [
                'type' => 'automation_event',
                'event_key' => 'webinar.attended',
            ],
            'name' => 'Webinar Attended Status Transition',
            'version' => 1,
            'is_active' => true,
            'source_version' => 'client_readiness_2026_07',
            'meta' => [
                'description' => 'Move contacts who attended a webinar into the attended_webinar workflow status.',
                'category' => 'webinar',
                'default_role' => 'status_transition',
            ],
            'points' => [
                [
                    'key' => 'change_status_to_attended_webinar',
                    'type' => 'change_status',
                    'capability_key' => 'flow_routes.change_status',
                    'name' => 'Change Status to Attended Webinar',
                    'description' => 'Move the contact into the attended_webinar status after a webinar.attended event.',
                    'default_definition' => [
                        'contact_status_key' => 'attended_webinar',
                        'reason' => 'webinar_attended_event',
                        'force' => false,
                        'on_same_status' => 'skipped',
                        'meta' => [
                            'source' => 'flow_route',
                            'trigger_type' => 'automation_event',
                            'event_key' => 'webinar.attended',
                        ],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'client_readiness_2026_07',
                    'meta' => [
                        'description' => 'Status transition point for attended webinar outcomes.',
                    ],
                    'sort_order' => 1,
                    'cancel_conditions' => [],
                    'is_start' => true,
                ],
            ],
        ],
        'webinar_missed_status_transition' => [
            'key' => 'webinar_missed_status_transition',
            'trigger' => [
                'type' => 'automation_event',
                'event_key' => 'webinar.missed',
            ],
            'name' => 'Webinar Missed Status Transition',
            'version' => 1,
            'is_active' => true,
            'source_version' => 'client_readiness_2026_07',
            'meta' => [
                'description' => 'Move contacts who missed a webinar into the missed_webinar workflow status.',
                'category' => 'webinar',
                'default_role' => 'status_transition',
            ],
            'points' => [
                [
                    'key' => 'change_status_to_missed_webinar',
                    'type' => 'change_status',
                    'capability_key' => 'flow_routes.change_status',
                    'name' => 'Change Status to Missed Webinar',
                    'description' => 'Move the contact into the missed_webinar status after a webinar.missed event.',
                    'default_definition' => [
                        'contact_status_key' => 'missed_webinar',
                        'reason' => 'webinar_missed_event',
                        'force' => false,
                        'on_same_status' => 'skipped',
                        'meta' => [
                            'source' => 'flow_route',
                            'trigger_type' => 'automation_event',
                            'event_key' => 'webinar.missed',
                        ],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'client_readiness_2026_07',
                    'meta' => [
                        'description' => 'Status transition point for missed webinar outcomes.',
                    ],
                    'sort_order' => 1,
                    'cancel_conditions' => [],
                    'is_start' => true,
                ],
            ],
        ],
        'webinar_attended_campaign_enrollment' => [
            'key' => 'webinar_attended_campaign_enrollment',
            'trigger' => [
                'type' => 'automation_event',
                'event_key' => 'webinar.attended',
            ],
            'name' => 'Webinar Attended Follow-Up',
            'version' => 1,
            'is_active' => true,
            'source_version' => 'phase_19_default',
            'meta' => [
                'description' => 'Enroll contacts who attended a webinar into the attended nurture campaign.',
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
                    'default_definition' => [
                        'campaign_key' => 'webinar_attended_nurture',
                        'on_already_enrolled' => 'skipped',
                        'payload' => [],
                        'meta' => [
                            'source' => 'flow_route',
                            'reason' => 'webinar_attended_event',
                        ],
                        'start_context' => [
                            'source' => 'flow_route',
                            'trigger_type' => 'automation_event',
                            'event_key' => 'webinar.attended',
                        ],
                        'exit_conditions' => [],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'phase_19_default',
                    'meta' => [
                        'description' => 'Campaign enrollment point for attended webinar follow-up.',
                    ],
                    'sort_order' => 1,
                    'cancel_conditions' => [],
                    'is_start' => true,
                ],
            ],
        ],
        'webinar_missed_campaign_enrollment' => [
            'key' => 'webinar_missed_campaign_enrollment',
            'trigger' => [
                'type' => 'automation_event',
                'event_key' => 'webinar.missed',
            ],
            'name' => 'Webinar Missed Follow-Up',
            'version' => 1,
            'is_active' => true,
            'source_version' => 'phase_19_default',
            'meta' => [
                'description' => 'Enroll contacts who missed a webinar into the missed webinar nurture campaign.',
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
                    'default_definition' => [
                        'campaign_key' => 'webinar_missed_nurture',
                        'on_already_enrolled' => 'skipped',
                        'payload' => [],
                        'meta' => [
                            'source' => 'flow_route',
                            'reason' => 'webinar_missed_event',
                        ],
                        'start_context' => [
                            'source' => 'flow_route',
                            'trigger_type' => 'automation_event',
                            'event_key' => 'webinar.missed',
                        ],
                        'exit_conditions' => [],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'phase_19_default',
                    'meta' => [
                        'description' => 'Campaign enrollment point for missed webinar follow-up.',
                    ],
                    'sort_order' => 1,
                    'cancel_conditions' => [],
                    'is_start' => true,
                ],
            ],
        ],
    ],
];
