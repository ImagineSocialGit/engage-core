<?php

$runtimeTargets = [
    'App\\Models\\User' => 'users',
    'App\\Modules\\Core\\Models\\Contact' => 'contacts',
    'App\\Modules\\Workflow\\Models\\ContactWorkflowProfile' => 'contact_workflow_profiles',
    'App\\Modules\\Messaging\\Models\\MessageChainEnrollment' => 'message_chain_enrollments',
    'App\\Modules\\Messaging\\Models\\ScheduledMessage' => 'scheduled_messages',
    'App\\Modules\\Webinars\\Models\\WebinarSeries' => 'webinar_series',
    'App\\Modules\\Webinars\\Models\\Webinar' => 'webinars',
    'App\\Modules\\Webinars\\Models\\WebinarRegistration' => 'webinar_registrations',
    'App\\Modules\\Webinars\\Models\\WebinarWaitlistSignup' => 'webinar_waitlist_signups',
    'App\\Modules\\Tasks\\Models\\Task' => 'tasks',
    'App\\Modules\\Campaigns\\Models\\Campaign' => 'campaigns',
    'App\\Modules\\Campaigns\\Models\\CampaignEnrollment' => 'campaign_enrollments',
    'App\\Modules\\Broadcasts\\Models\\Broadcast' => 'broadcasts',
    'App\\Modules\\InternalNotifications\\Models\\TeamMember' => 'team_members',
    'App\\Modules\\Scheduling\\Models\\Appointment' => 'appointments',

    'contact' => 'contacts',
    'workflow_profile' => 'contact_workflow_profiles',
    'message_chain_enrollment' => 'message_chain_enrollments',
    'scheduled_message' => 'scheduled_messages',
    'webinar_series' => 'webinar_series',
    'webinar' => 'webinars',
    'webinar_registration' => 'webinar_registrations',
    'webinar_waitlist_signup' => 'webinar_waitlist_signups',
    'task' => 'tasks',
    'campaign' => 'campaigns',
    'campaign_enrollment' => 'campaign_enrollments',
    'broadcast' => 'broadcasts',
    'team_member' => 'team_members',
    'appointment' => 'appointments',
];

$contactStatusJsonReferences = [
    'contact_status_id' => [
        'table' => 'contact_statuses',
    ],
    'status_id' => [
        'table' => 'contact_statuses',
    ],
    'target_contact_status_id' => [
        'table' => 'contact_statuses',
    ],
    'target_status_id' => [
        'table' => 'contact_statuses',
    ],
];

$statusBackup = [
    'status' => [
        'json_column' => 'meta',
        'path' => 'project_state.original_status',
    ],
];

return [
    'version' => 1,
    'tables' => [
        'flow_route_capabilities' => [
            'mode' => 'upsert',
            'identity' => ['key'],
            'preserve_id' => false,
            'order_by' => ['key'],
            'columns' => [
                'id',
                'key',
                'module_key',
                'capability_type',
                'point_type',
                'handler_key',
                'event_key',
                'action_key',
                'name',
                'description',
                'category',
                'surface',
                'supported_subjects',
                'required_modules',
                'input_schema',
                'output_schema',
                'available_fields',
                'defaults',
                'is_active',
                'source',
                'source_version',
                'is_customized',
                'customized_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'supported_subjects',
                'required_modules',
                'input_schema',
                'output_schema',
                'available_fields',
                'defaults',
                'meta',
            ],
        ],

        'flow_route_capability_bindings' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'flow_route_capability_id',
                'context_type',
                'context_id',
                'owner_type',
                'owner_id',
                'module_key',
                'visibility',
                'sort_order',
                'label',
                'description',
                'help_text',
                'defaults',
                'constraints',
                'input_overrides',
                'output_overrides',
                'is_enabled',
                'is_customized',
                'customized_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'defaults',
                'constraints',
                'input_overrides',
                'output_overrides',
                'meta',
            ],
            'references' => [
                'flow_route_capability_id' => 'flow_route_capabilities',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'context_type',
                    'id_column' => 'context_id',
                    'targets' => $runtimeTargets,
                ],
                [
                    'type_column' => 'owner_type',
                    'id_column' => 'owner_id',
                    'targets' => $runtimeTargets,
                ],
            ],
        ],

        'flow_routes' => [
            'mode' => 'upsert',
            'identity' => [
                'key',
                'version',
            ],
            'preserve_id' => false,
            'order_by' => ['key', 'version', 'id'],
            'columns' => [
                'id',
                'key',
                'contact_status_id',
                'owner_type',
                'owner_id',
                'owner_group',
                'name',
                'description',
                'version',
                'is_current_version',
                'trigger_type',
                'trigger_key',
                'is_active',
                'source_version',
                'is_customized',
                'customized_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'contact_status_id' => 'contact_statuses',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'owner_type',
                    'id_column' => 'owner_id',
                    'targets' => $runtimeTargets,
                ],
            ],
        ],

        'flow_route_trigger_bindings' => [
            'mode' => 'upsert',
            'identity' => [
                'trigger_type',
                'trigger_key',
                'flow_route_id',
                'context_type',
                'context_id',
            ],
            'nullable_identity' => [
                'trigger_key',
                'context_type',
                'context_id',
            ],
            'preserve_id' => false,
            'order_by' => ['flow_route_id', 'trigger_type', 'trigger_key', 'id'],
            'columns' => [
                'id',
                'trigger_type',
                'trigger_key',
                'flow_route_id',
                'context_type',
                'context_id',
                'is_active',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'flow_route_id' => 'flow_routes',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'context_type',
                    'id_column' => 'context_id',
                    'targets' => $runtimeTargets,
                ],
            ],
        ],

        'flow_route_points' => [
            'mode' => 'upsert',
            'identity' => [
                'flow_route_id',
                'key',
            ],
            'preserve_id' => false,
            'order_by' => ['flow_route_id', 'sort_order', 'id'],
            'columns' => [
                'id',
                'flow_route_id',
                'flow_route_capability_id',
                'key',
                'type',
                'name',
                'description',
                'sort_order',
                'is_start',
                'is_active',
                'next_flow_route_point_id',
                'definition',
                'settings',
                'cancel_conditions',
                'source_version',
                'is_customized',
                'customized_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'definition',
                'settings',
                'cancel_conditions',
                'meta',
            ],
            'references' => [
                'flow_route_id' => 'flow_routes',
                'flow_route_capability_id' => 'flow_route_capabilities',
            ],
            'deferred_references' => [
                'next_flow_route_point_id' => 'flow_route_points',
            ],
            'json_path_references' => [
                'definition' => $contactStatusJsonReferences,
                'settings' => $contactStatusJsonReferences,
            ],
        ],

        'contact_flow_route_progress' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'contact_id',
                'subject_type',
                'subject_id',
                'contact_status_id',
                'contact_workflow_profile_id',
                'flow_route_id',
                'current_flow_route_point_id',
                'status',
                'started_at',
                'completed_at',
                'cancelled_at',
                'failed_at',
                'resume_at',
                'waiting_event_key',
                'cancellation_reason',
                'failure_reason',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'contact_id' => 'contacts',
                'contact_status_id' => 'contact_statuses',
                'contact_workflow_profile_id' => 'contact_workflow_profiles',
                'flow_route_id' => 'flow_routes',
                'current_flow_route_point_id' => 'flow_route_points',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'subject_type',
                    'id_column' => 'subject_id',
                    'targets' => $runtimeTargets,
                ],
            ],
            'import_value_maps' => [
                'status' => [
                    'active' => 'paused',
                    'waiting' => 'paused',
                ],
            ],
            'import_value_map_backups' => $statusBackup,
            'json_path_references' => [
                'meta' => [
                    'started_from_workflow_transition.from_contact_status_id' => [
                        'table' => 'contact_statuses',
                    ],
                    'started_from_workflow_transition.to_contact_status_id' => [
                        'table' => 'contact_statuses',
                    ],
                    'waiting.flow_route_plan_id' => [
                        'table' => 'contact_flow_route_plans',
                        'deferred' => true,
                    ],
                    'waiting.flow_route_plan_item_id' => [
                        'table' => 'contact_flow_route_plan_items',
                        'deferred' => true,
                    ],
                    'waiting.flow_route_progress_item_id' => [
                        'table' => 'contact_flow_route_progress_items',
                        'deferred' => true,
                    ],
                    'waiting.flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'immediate_execution_continuation.flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'last_version_reconciliation.from_flow_route_id' => [
                        'table' => 'flow_routes',
                    ],
                    'last_version_reconciliation.from_flow_route_plan_id' => [
                        'table' => 'contact_flow_route_plans',
                        'deferred' => true,
                    ],
                    'last_version_reconciliation.from_flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'last_version_reconciliation.to_flow_route_id' => [
                        'table' => 'flow_routes',
                    ],
                    'last_version_reconciliation.to_flow_route_plan_id' => [
                        'table' => 'contact_flow_route_plans',
                        'deferred' => true,
                    ],
                    'last_version_reconciliation.to_flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                ],
            ],
        ],

        'contact_flow_route_plans' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['contact_flow_route_progress_id', 'revision', 'id'],
            'columns' => [
                'id',
                'contact_flow_route_progress_id',
                'contact_id',
                'subject_type',
                'subject_id',
                'flow_route_id',
                'status',
                'source',
                'revision',
                'flow_route_version',
                'snapshot_at',
                'started_at',
                'completed_at',
                'cancelled_at',
                'failed_at',
                'superseded_at',
                'cancellation_reason',
                'failure_reason',
                'reconciled_from_plan_id',
                'route_snapshot',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'route_snapshot',
                'meta',
            ],
            'references' => [
                'contact_flow_route_progress_id' => 'contact_flow_route_progress',
                'contact_id' => 'contacts',
                'flow_route_id' => 'flow_routes',
            ],
            'deferred_references' => [
                'reconciled_from_plan_id' => 'contact_flow_route_plans',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'subject_type',
                    'id_column' => 'subject_id',
                    'targets' => $runtimeTargets,
                ],
            ],
            'import_value_maps' => [
                'status' => [
                    'active' => 'paused',
                ],
            ],
            'import_value_map_backups' => $statusBackup,
            'json_path_references' => [
                'route_snapshot' => [
                    'id' => [
                        'table' => 'flow_routes',
                    ],
                ],
                'meta' => [
                    'reconciled_from_plan_id' => [
                        'table' => 'contact_flow_route_plans',
                        'deferred' => true,
                    ],
                    'reconciled_from_flow_route_id' => [
                        'table' => 'flow_routes',
                    ],
                ],
            ],
        ],

        'contact_flow_route_plan_items' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['contact_flow_route_plan_id', 'sort_order', 'sequence', 'id'],
            'columns' => [
                'id',
                'contact_flow_route_progress_id',
                'contact_flow_route_plan_id',
                'flow_route_id',
                'flow_route_point_id',
                'flow_route_capability_id',
                'key',
                'point_type',
                'sort_order',
                'sequence',
                'attempt',
                'source',
                'status',
                'result_reason',
                'available_at',
                'started_at',
                'completed_at',
                'skipped_at',
                'cancelled_at',
                'failed_at',
                'resume_at',
                'waiting_event_key',
                'definition_snapshot',
                'settings_snapshot',
                'cancel_conditions_snapshot',
                'correlation',
                'result_payload',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'definition_snapshot',
                'settings_snapshot',
                'cancel_conditions_snapshot',
                'correlation',
                'result_payload',
                'meta',
            ],
            'references' => [
                'contact_flow_route_progress_id' => 'contact_flow_route_progress',
                'contact_flow_route_plan_id' => 'contact_flow_route_plans',
                'flow_route_id' => 'flow_routes',
                'flow_route_point_id' => 'flow_route_points',
                'flow_route_capability_id' => 'flow_route_capabilities',
            ],
            'import_value_maps' => [
                'status' => [
                    'pending' => 'paused',
                    'active' => 'paused',
                    'waiting' => 'paused',
                ],
            ],
            'import_value_map_backups' => $statusBackup,
            'json_path_references' => [
                'definition_snapshot' => $contactStatusJsonReferences,
                'settings_snapshot' => $contactStatusJsonReferences,
                'result_payload' => [
                    'meta.advance_to_flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'meta.advance_to_flow_route_plan_item_id' => [
                        'table' => 'contact_flow_route_plan_items',
                        'deferred' => true,
                    ],
                    'meta.flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'meta.flow_routes.flow_route_progress_id' => [
                        'table' => 'contact_flow_route_progress',
                    ],
                    'meta.flow_routes.flow_route_plan_id' => [
                        'table' => 'contact_flow_route_plans',
                    ],
                    'meta.flow_routes.flow_route_plan_item_id' => [
                        'table' => 'contact_flow_route_plan_items',
                        'deferred' => true,
                    ],
                    'meta.flow_routes.flow_route_progress_item_id' => [
                        'table' => 'contact_flow_route_progress_items',
                        'deferred' => true,
                    ],
                    'meta.flow_routes.flow_route_id' => [
                        'table' => 'flow_routes',
                    ],
                    'meta.flow_routes.flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'meta.flow_routes.flow_route_capability_id' => [
                        'table' => 'flow_route_capabilities',
                    ],
                ],
                'meta' => [
                    'flow_route_point_snapshot.id' => [
                        'table' => 'flow_route_points',
                    ],
                    'flow_route_point_snapshot.next_flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'flow_route_point_snapshot.flow_route_capability_id' => [
                        'table' => 'flow_route_capabilities',
                    ],
                    'reconciliation.carried_from_plan_item_id' => [
                        'table' => 'contact_flow_route_plan_items',
                        'deferred' => true,
                    ],
                ],
            ],
        ],

        'contact_flow_route_progress_items' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['contact_flow_route_progress_id', 'sequence', 'attempt', 'id'],
            'columns' => [
                'id',
                'contact_flow_route_progress_id',
                'contact_flow_route_plan_id',
                'contact_flow_route_plan_item_id',
                'flow_route_id',
                'flow_route_point_id',
                'flow_route_capability_id',
                'created_subject_type',
                'created_subject_id',
                'key',
                'point_type',
                'sequence',
                'attempt',
                'status',
                'result_reason',
                'started_at',
                'completed_at',
                'skipped_at',
                'cancelled_at',
                'failed_at',
                'resume_at',
                'waiting_event_key',
                'correlation_key',
                'correlation_type',
                'correlation',
                'result_payload',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'correlation',
                'result_payload',
                'meta',
            ],
            'references' => [
                'contact_flow_route_progress_id' => 'contact_flow_route_progress',
                'contact_flow_route_plan_id' => 'contact_flow_route_plans',
                'contact_flow_route_plan_item_id' => 'contact_flow_route_plan_items',
                'flow_route_id' => 'flow_routes',
                'flow_route_point_id' => 'flow_route_points',
                'flow_route_capability_id' => 'flow_route_capabilities',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'created_subject_type',
                    'id_column' => 'created_subject_id',
                    'targets' => $runtimeTargets,
                ],
            ],
            'import_value_maps' => [
                'status' => [
                    'started' => 'paused',
                    'waiting' => 'paused',
                ],
            ],
            'import_value_map_backups' => $statusBackup,
            'json_path_references' => [
                'result_payload' => [
                    'meta.advance_to_flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'meta.advance_to_flow_route_plan_item_id' => [
                        'table' => 'contact_flow_route_plan_items',
                    ],
                    'meta.flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'meta.flow_routes.flow_route_progress_id' => [
                        'table' => 'contact_flow_route_progress',
                    ],
                    'meta.flow_routes.flow_route_plan_id' => [
                        'table' => 'contact_flow_route_plans',
                    ],
                    'meta.flow_routes.flow_route_plan_item_id' => [
                        'table' => 'contact_flow_route_plan_items',
                    ],
                    'meta.flow_routes.flow_route_progress_item_id' => [
                        'table' => 'contact_flow_route_progress_items',
                        'deferred' => true,
                    ],
                    'meta.flow_routes.flow_route_id' => [
                        'table' => 'flow_routes',
                    ],
                    'meta.flow_routes.flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'meta.flow_routes.flow_route_capability_id' => [
                        'table' => 'flow_route_capabilities',
                    ],
                ],
                'meta' => [
                    'flow_routes.flow_route_progress_id' => [
                        'table' => 'contact_flow_route_progress',
                    ],
                    'flow_routes.flow_route_plan_id' => [
                        'table' => 'contact_flow_route_plans',
                    ],
                    'flow_routes.flow_route_plan_item_id' => [
                        'table' => 'contact_flow_route_plan_items',
                    ],
                    'flow_routes.flow_route_progress_item_id' => [
                        'table' => 'contact_flow_route_progress_items',
                        'deferred' => true,
                    ],
                    'flow_routes.flow_route_id' => [
                        'table' => 'flow_routes',
                    ],
                    'flow_routes.flow_route_point_id' => [
                        'table' => 'flow_route_points',
                    ],
                    'flow_routes.flow_route_capability_id' => [
                        'table' => 'flow_route_capabilities',
                    ],
                ],
            ],
        ],
    ],
];