<?php

return [
    'groups' => [
        'webinar_default' => ['webinar_attended_status_transition', 'webinar_missed_status_transition', 'webinar_attended_campaign_enrollment', 'webinar_missed_campaign_enrollment'],
        'mortgage_default' => ['webinar_attended_status_transition', 'webinar_missed_status_transition', 'webinar_attended_campaign_enrollment', 'webinar_missed_campaign_enrollment', 'smoke_webinar_attended_nurture_test_enrollment', 'smoke_prospect_cancel_nurture_and_create_task'],
    ],
    'definitions' => [
        'webinar_attended_status_transition' => [
            'key' => 'webinar_attended_status_transition', 'trigger' => ['type' => 'automation_event', 'event_key' => 'webinar.attended'], 'name' => 'Webinar Attended Status Transition', 'version' => 1, 'is_active' => true, 'source_version' => 'client_readiness_2026_07', 'meta' => ['description' => 'Move contacts who attended a webinar into the attended_webinar workflow status.', 'category' => 'webinar', 'default_role' => 'status_transition'],
            'points' => [[
                'key' => 'change_status_to_attended_webinar', 'type' => 'change_status', 'name' => 'Change Status to Attended Webinar', 'description' => 'Move the contact into the attended_webinar status after a webinar.attended event.',
                'default_definition' => ['contact_status_key' => 'attended_webinar', 'reason' => 'webinar_attended_event', 'force' => false, 'on_same_status' => 'skipped', 'meta' => ['source' => 'flow_route', 'trigger_type' => 'automation_event', 'event_key' => 'webinar.attended']],
                'default_settings' => [], 'is_active' => true, 'source_version' => 'client_readiness_2026_07', 'meta' => ['description' => 'Status transition point for attended webinar outcomes.'], 'sort_order' => 1, 'cancel_conditions' => [], 'is_start' => true,
            ]],
        ],
        'webinar_missed_status_transition' => [
            'key' => 'webinar_missed_status_transition', 'trigger' => ['type' => 'automation_event', 'event_key' => 'webinar.missed'], 'name' => 'Webinar Missed Status Transition', 'version' => 1, 'is_active' => true, 'source_version' => 'client_readiness_2026_07', 'meta' => ['description' => 'Move contacts who missed a webinar into the missed_webinar workflow status.', 'category' => 'webinar', 'default_role' => 'status_transition'],
            'points' => [[
                'key' => 'change_status_to_missed_webinar', 'type' => 'change_status', 'name' => 'Change Status to Missed Webinar', 'description' => 'Move the contact into the missed_webinar status after a webinar.missed event.',
                'default_definition' => ['contact_status_key' => 'missed_webinar', 'reason' => 'webinar_missed_event', 'force' => false, 'on_same_status' => 'skipped', 'meta' => ['source' => 'flow_route', 'trigger_type' => 'automation_event', 'event_key' => 'webinar.missed']],
                'default_settings' => [], 'is_active' => true, 'source_version' => 'client_readiness_2026_07', 'meta' => ['description' => 'Status transition point for missed webinar outcomes.'], 'sort_order' => 1, 'cancel_conditions' => [], 'is_start' => true,
            ]],
        ],
        'webinar_attended_campaign_enrollment' => [
            'key' => 'webinar_attended_campaign_enrollment', 'trigger' => ['type' => 'automation_event', 'event_key' => 'webinar.attended'], 'name' => 'Webinar Attended Follow-Up', 'version' => 1, 'is_active' => true, 'source_version' => 'phase_19_default', 'meta' => ['description' => 'Enroll contacts who attended a webinar into the attended nurture campaign.', 'category' => 'webinar', 'default_role' => 'campaign_enrollment'],
            'points' => [[
                'key' => 'enroll_webinar_attended_nurture', 'type' => 'enroll_campaign', 'name' => 'Enroll Webinar Attended Nurture', 'description' => 'Enroll the contact into the attended webinar nurture campaign.',
                'default_definition' => ['campaign_key' => 'webinar_attended_nurture', 'on_already_enrolled' => 'skipped', 'payload' => [], 'meta' => ['source' => 'flow_route', 'reason' => 'webinar_attended_event'], 'start_context' => ['source' => 'flow_route', 'trigger_type' => 'automation_event', 'event_key' => 'webinar.attended'], 'exit_conditions' => []],
                'default_settings' => [], 'is_active' => true, 'source_version' => 'phase_19_default', 'meta' => ['description' => 'Campaign enrollment point for attended webinar follow-up.'], 'sort_order' => 1, 'cancel_conditions' => [], 'is_start' => true,
            ]],
        ],
        'webinar_missed_campaign_enrollment' => [
            'key' => 'webinar_missed_campaign_enrollment', 'trigger' => ['type' => 'automation_event', 'event_key' => 'webinar.missed'], 'name' => 'Webinar Missed Follow-Up', 'version' => 1, 'is_active' => true, 'source_version' => 'phase_19_default', 'meta' => ['description' => 'Enroll contacts who missed a webinar into the missed webinar nurture campaign.', 'category' => 'webinar', 'default_role' => 'campaign_enrollment'],
            'points' => [[
                'key' => 'enroll_webinar_missed_nurture', 'type' => 'enroll_campaign', 'name' => 'Enroll Webinar Missed Nurture', 'description' => 'Enroll the contact into the missed webinar nurture campaign.',
                'default_definition' => ['campaign_key' => 'webinar_missed_nurture', 'on_already_enrolled' => 'skipped', 'payload' => [], 'meta' => ['source' => 'flow_route', 'reason' => 'webinar_missed_event'], 'start_context' => ['source' => 'flow_route', 'trigger_type' => 'automation_event', 'event_key' => 'webinar.missed'], 'exit_conditions' => []],
                'default_settings' => [], 'is_active' => true, 'source_version' => 'phase_19_default', 'meta' => ['description' => 'Campaign enrollment point for missed webinar follow-up.'], 'sort_order' => 1, 'cancel_conditions' => [], 'is_start' => true,
            ]],
        ],
        'smoke_webinar_attended_nurture_test_enrollment' => [
            'key' => 'smoke_webinar_attended_nurture_test_enrollment', 'trigger' => ['type' => 'automation_event', 'event_key' => 'webinar.attended'], 'name' => 'Smoke Webinar Attended Test Nurture Enrollment', 'version' => 1, 'is_active' => true, 'source_version' => 'smoke_test_2026_07', 'meta' => ['description' => 'Disposable smoke route that enrolls attended webinar contacts into short email and SMS test nurture campaigns.', 'category' => 'smoke_test', 'temporary' => true],
            'points' => [
                ['key' => 'enroll_webinar_attended_nurture_email_test', 'type' => 'enroll_campaign', 'name' => 'Enroll Attended Email Test Nurture', 'description' => 'Enroll the contact into the disposable attended webinar email test campaign.', 'default_definition' => ['campaign_key' => 'webinar_attended_nurture_email_test', 'on_already_enrolled' => 'skipped', 'payload' => [], 'meta' => ['source' => 'flow_route_smoke_test', 'reason' => 'webinar_attended_event'], 'start_context' => ['source' => 'flow_route_smoke_test', 'trigger_type' => 'automation_event', 'event_key' => 'webinar.attended'], 'exit_conditions' => []], 'default_settings' => [], 'is_active' => true, 'source_version' => 'smoke_test_2026_07', 'meta' => ['description' => 'Smoke-test email campaign enrollment point.'], 'sort_order' => 1, 'is_start' => true, 'next_point_key' => 'enroll_webinar_attended_nurture_sms_test', 'cancel_conditions' => []],
                ['key' => 'enroll_webinar_attended_nurture_sms_test', 'type' => 'enroll_campaign', 'name' => 'Enroll Attended SMS Test Nurture', 'description' => 'Enroll the contact into the disposable attended webinar SMS test campaign.', 'default_definition' => ['campaign_key' => 'webinar_attended_nurture_sms_test', 'on_already_enrolled' => 'skipped', 'payload' => [], 'meta' => ['source' => 'flow_route_smoke_test', 'reason' => 'webinar_attended_event'], 'start_context' => ['source' => 'flow_route_smoke_test', 'trigger_type' => 'automation_event', 'event_key' => 'webinar.attended'], 'exit_conditions' => []], 'default_settings' => [], 'is_active' => true, 'source_version' => 'smoke_test_2026_07', 'meta' => ['description' => 'Smoke-test SMS campaign enrollment point.'], 'sort_order' => 2, 'cancel_conditions' => []],
            ],
        ],
        'smoke_prospect_cancel_nurture_and_create_task' => [
            'key' => 'smoke_prospect_cancel_nurture_and_create_task', 'contact_status_key' => 'prospect', 'trigger' => ['type' => 'contact_status'], 'name' => 'Smoke Prospect Cancels Nurture and Creates Task', 'version' => 1, 'is_active' => true, 'source_version' => 'smoke_test_2026_07', 'meta' => ['description' => 'Disposable smoke route that cancels attended webinar nurture campaigns and creates the prospect task.', 'category' => 'smoke_test', 'temporary' => true],
            'points' => [
                ['key' => 'smoke_cancel_attended_email_nurture', 'type' => 'cancel_campaign', 'name' => 'Cancel Smoke Attended Email Nurture', 'description' => 'Cancel the pending smoke attended email campaign when the contact becomes a prospect.', 'default_definition' => ['campaign_key' => 'webinar_attended_nurture_email_test', 'reason' => 'prospect_status_changed', 'skip_pending_messages' => true, 'on_not_enrolled' => 'completed', 'meta' => ['source' => 'flow_route_smoke_test']], 'default_settings' => [], 'is_active' => true, 'source_version' => 'smoke_test_2026_07', 'meta' => ['description' => 'Cancel smoke email campaign.'], 'sort_order' => 1, 'is_start' => true, 'next_point_key' => 'smoke_cancel_attended_sms_nurture', 'cancel_conditions' => []],
                ['key' => 'smoke_cancel_attended_sms_nurture', 'type' => 'cancel_campaign', 'name' => 'Cancel Smoke Attended SMS Nurture', 'description' => 'Cancel the pending smoke attended SMS campaign when the contact becomes a prospect.', 'default_definition' => ['campaign_key' => 'webinar_attended_nurture_sms_test', 'reason' => 'prospect_status_changed', 'skip_pending_messages' => true, 'on_not_enrolled' => 'completed', 'meta' => ['source' => 'flow_route_smoke_test']], 'default_settings' => [], 'is_active' => true, 'source_version' => 'smoke_test_2026_07', 'meta' => ['description' => 'Cancel smoke SMS campaign.'], 'sort_order' => 2, 'next_point_key' => 'smoke_create_prospect_task', 'cancel_conditions' => []],
                ['key' => 'smoke_create_prospect_task', 'type' => 'create_task', 'name' => 'Create The Prospect Task', 'description' => 'Create and assign the prospect task after attended campaign cancellation.', 'default_definition' => ['title' => 'The prospect task', 'description' => 'Smoke test prospect task created automatically after the contact status changes to Prospect.', 'assigned_to' => 'only_active_team_member', 'responsible_party' => 'internal', 'priority' => 'normal', 'meta' => ['source' => 'flow_route_smoke_test', 'temporary' => true]], 'default_settings' => [], 'is_active' => true, 'source_version' => 'smoke_test_2026_07', 'meta' => ['description' => 'Task creation point for the Prospect smoke route.'], 'sort_order' => 3, 'cancel_conditions' => []],
            ],
        ],
    ],
];
