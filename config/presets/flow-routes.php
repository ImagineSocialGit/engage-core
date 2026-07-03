<?php

return [
    'groups' => [
        'webinar_default' => [
            'webinar_attended_campaign_enrollment',
            'webinar_missed_campaign_enrollment',
        ],
        'mortgage_default' => [
            'webinar_attended_campaign_enrollment',
            'webinar_missed_campaign_enrollment',
            'smoke_webinar_attended_nurture_test_enrollment',
            'smoke_attended_webinar_to_in_process',
            'smoke_in_process_task_completion_message',
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
                        'flow_route_point' => [
                            'description' => 'First and only point in the attended webinar route.',
                        ],
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
                        'flow_route_point' => [
                            'description' => 'First and only point in the missed webinar route.',
                        ],
                    ],
                    'sort_order' => 1,
                    'cancel_conditions' => [],
                    'is_start' => true,
                ],
            ],
        ],
        'smoke_webinar_attended_nurture_test_enrollment' => [
            'key' => 'smoke_webinar_attended_nurture_test_enrollment',
            'trigger' => [
                'type' => 'automation_event',
                'event_key' => 'webinar.attended',
            ],
            'name' => 'Smoke Webinar Attended Test Nurture Enrollment',
            'version' => 1,
            'is_active' => true,
            'source_version' => 'smoke_test_2026_07',
            'meta' => [
                'description' => 'Disposable smoke route that enrolls attended webinar contacts into short email and SMS test nurture campaigns.',
                'category' => 'smoke_test',
                'default_role' => 'campaign_enrollment',
                'temporary' => true,
            ],
            'points' => [
                [
                    'key' => 'enroll_webinar_attended_nurture_email_test',
                    'type' => 'enroll_campaign',
                    'name' => 'Enroll Attended Email Test Nurture',
                    'description' => 'Enroll the contact into the disposable attended webinar email test campaign.',
                    'default_definition' => [
                        'campaign_key' => 'webinar_attended_nurture_email_test',
                        'on_already_enrolled' => 'skipped',
                        'payload' => [],
                        'meta' => [
                            'source' => 'flow_route_smoke_test',
                            'reason' => 'webinar_attended_event',
                        ],
                        'start_context' => [
                            'source' => 'flow_route_smoke_test',
                            'trigger_type' => 'automation_event',
                            'event_key' => 'webinar.attended',
                        ],
                        'exit_conditions' => [],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'smoke_test_2026_07',
                    'meta' => [
                        'description' => 'Smoke-test email campaign enrollment point.',
                        'flow_route_point' => [
                            'description' => 'First point in disposable attended nurture smoke route.',
                        ],
                    ],
                    'sort_order' => 1,
                    'is_start' => true,
                    'next_point_key' => 'enroll_webinar_attended_nurture_sms_test',
                    'cancel_conditions' => [],
                ],
                [
                    'key' => 'enroll_webinar_attended_nurture_sms_test',
                    'type' => 'enroll_campaign',
                    'name' => 'Enroll Attended SMS Test Nurture',
                    'description' => 'Enroll the contact into the disposable attended webinar SMS test campaign.',
                    'default_definition' => [
                        'campaign_key' => 'webinar_attended_nurture_sms_test',
                        'on_already_enrolled' => 'skipped',
                        'payload' => [],
                        'meta' => [
                            'source' => 'flow_route_smoke_test',
                            'reason' => 'webinar_attended_event',
                        ],
                        'start_context' => [
                            'source' => 'flow_route_smoke_test',
                            'trigger_type' => 'automation_event',
                            'event_key' => 'webinar.attended',
                        ],
                        'exit_conditions' => [],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'smoke_test_2026_07',
                    'meta' => [
                        'description' => 'Smoke-test SMS campaign enrollment point.',
                        'flow_route_point' => [
                            'description' => 'Second point in disposable attended nurture smoke route.',
                        ],
                    ],
                    'sort_order' => 2,
                    'cancel_conditions' => [],
                ],
            ],
        ],
        'smoke_attended_webinar_to_in_process' => [
            'key' => 'smoke_attended_webinar_to_in_process',
            'contact_status_key' => 'attended_webinar',
            'trigger' => [
                'type' => 'contact_status',
            ],
            'name' => 'Smoke Attended Webinar to In Process',
            'version' => 1,
            'is_active' => true,
            'source_version' => 'smoke_test_2026_07',
            'meta' => [
                'description' => 'Disposable smoke route that changes attended webinar contacts to in_process.',
                'category' => 'smoke_test',
                'temporary' => true,
            ],
            'points' => [
                [
                    'key' => 'smoke_change_status_to_in_process',
                    'type' => 'change_status',
                    'name' => 'Change Status to In Process',
                    'description' => 'Move the contact into the in_process status to start the task-completion route.',
                    'default_definition' => [
                        'contact_status_key' => 'in_process',
                        'reason' => 'smoke_attended_webinar_to_in_process',
                        'force' => false,
                        'on_same_status' => 'skipped',
                        'meta' => [
                            'source' => 'flow_route_smoke_test',
                        ],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'smoke_test_2026_07',
                    'meta' => [
                        'description' => 'Status-change point for smoke testing route handoff behavior.',
                        'flow_route_point' => [
                            'description' => 'Only point in attended_webinar to in_process smoke route.',
                        ],
                    ],
                    'sort_order' => 1,
                    'is_start' => true,
                    'cancel_conditions' => [],
                ],
            ],
        ],
        'smoke_in_process_task_completion_message' => [
            'key' => 'smoke_in_process_task_completion_message',
            'contact_status_key' => 'in_process',
            'trigger' => [
                'type' => 'contact_status',
            ],
            'name' => 'Smoke In Process Task Completion Message',
            'version' => 1,
            'is_active' => true,
            'source_version' => 'smoke_test_2026_07',
            'meta' => [
                'description' => 'Disposable smoke route that creates a task, waits for task.completed, then sends email and SMS task-done messages.',
                'category' => 'smoke_test',
                'temporary' => true,
            ],
            'points' => [
                [
                    'key' => 'smoke_create_attended_webinar_review_task',
                    'type' => 'create_task',
                    'name' => 'Create Smoke Review Task',
                    'description' => 'Create a short follow-up task for the attended webinar smoke contact.',
                    'default_definition' => [
                        'title' => 'Smoke test: review attended webinar lead #{contact.id}',
                        'description' => 'Complete this task to resume the smoke FlowRoute and schedule the task-done email/SMS.',
                        'responsible_party' => 'internal',
                        'priority' => 'normal',
                        'meta' => [
                            'source' => 'flow_route_smoke_test',
                            'temporary' => true,
                        ],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'smoke_test_2026_07',
                    'meta' => [
                        'description' => 'Task creation point for smoke testing task completion resume behavior.',
                        'flow_route_point' => [
                            'description' => 'First point in in_process task-completion smoke route.',
                        ],
                    ],
                    'sort_order' => 1,
                    'is_start' => true,
                    'next_point_key' => 'smoke_wait_for_review_task_completed',
                    'cancel_conditions' => [],
                ],
                [
                    'key' => 'smoke_wait_for_review_task_completed',
                    'type' => 'event_wait',
                    'name' => 'Wait for Smoke Task Completed',
                    'description' => 'Wait until the contact has a task.completed automation event.',
                    'default_definition' => [
                        'event_key' => 'task.completed',
                        'correlation' => [],
                        'meta' => [
                            'source' => 'flow_route_smoke_test',
                        ],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'smoke_test_2026_07',
                    'meta' => [
                        'description' => 'Event wait point for task.completed smoke testing.',
                        'flow_route_point' => [
                            'description' => 'Second point in in_process task-completion smoke route.',
                        ],
                    ],
                    'sort_order' => 2,
                    'next_point_key' => 'smoke_send_task_done_email',
                    'cancel_conditions' => [],
                ],
                [
                    'key' => 'smoke_send_task_done_email',
                    'type' => 'send_message',
                    'name' => 'Send Task Done Email',
                    'description' => 'Schedule the disposable route-test email after task completion.',
                    'default_definition' => [
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'route_test',
                        'dispatch_keys' => [
                            'flow_route_task_done',
                        ],
                        'payload' => [],
                        'criteria' => [],
                        'on_no_messages' => 'skipped',
                        'meta' => [
                            'source' => 'flow_route_smoke_test',
                        ],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'smoke_test_2026_07',
                    'meta' => [
                        'description' => 'Route-test email send-message point.',
                        'flow_route_point' => [
                            'description' => 'Third point in in_process task-completion smoke route.',
                        ],
                    ],
                    'sort_order' => 3,
                    'next_point_key' => 'smoke_send_task_done_sms',
                    'cancel_conditions' => [],
                ],
                [
                    'key' => 'smoke_send_task_done_sms',
                    'type' => 'send_message',
                    'name' => 'Send Task Done SMS',
                    'description' => 'Schedule the disposable route-test SMS after task completion.',
                    'default_definition' => [
                        'channel' => 'sms',
                        'purpose' => 'transactional',
                        'scope' => 'route_test',
                        'dispatch_keys' => [
                            'flow_route_task_done',
                        ],
                        'payload' => [],
                        'criteria' => [],
                        'on_no_messages' => 'skipped',
                        'meta' => [
                            'source' => 'flow_route_smoke_test',
                        ],
                    ],
                    'default_settings' => [],
                    'is_active' => true,
                    'source_version' => 'smoke_test_2026_07',
                    'meta' => [
                        'description' => 'Route-test SMS send-message point.',
                        'flow_route_point' => [
                            'description' => 'Final point in in_process task-completion smoke route.',
                        ],
                    ],
                    'sort_order' => 4,
                    'cancel_conditions' => [],
                ],
            ],
        ],
    ],
];