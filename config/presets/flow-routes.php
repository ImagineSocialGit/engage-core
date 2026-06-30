<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FlowRoute Presets
    |--------------------------------------------------------------------------
    |
    | FlowRoute presets create DB-owned FlowRoute, Point, and FlowRoutePoint
    | records. Runtime FlowRoute execution reads database definitions, not this
    | config file.
    |
    | Keep default routes small and client-safe. FlowRoutes should make
    | automation/control decisions. Campaigns should own the message journey.
    |
    */

    'groups' => [

        'webinar_default' => [
            'webinar_attended_campaign_enrollment',
            'webinar_missed_campaign_enrollment',
        ],

        'mortgage_default' => [
            'webinar_attended_campaign_enrollment',
            'webinar_missed_campaign_enrollment',
        ],

    ],

    'definitions' => [

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
                    'route_point' => [
                        'sort_order' => 1,
                        'is_active' => true,
                        'definition' => [],
                        'settings' => [],
                        'cancel_conditions' => [],
                        'source_version' => 'phase_19_default',
                        'meta' => [
                            'description' => 'First and only point in the attended webinar route.',
                        ],
                    ],
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
                    'route_point' => [
                        'sort_order' => 1,
                        'is_active' => true,
                        'definition' => [],
                        'settings' => [],
                        'cancel_conditions' => [],
                        'source_version' => 'phase_19_default',
                        'meta' => [
                            'description' => 'First and only point in the missed webinar route.',
                        ],
                    ],
                ],
            ],
        ],

    ],

];