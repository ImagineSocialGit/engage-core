<?php

return [
    'version' => 1,
    'tables' => [
        'automation_event_outbox_events' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'event_id',
                'idempotency_key',
                'event_key',
                'contact_id',
                'subject_type',
                'subject_id',
                'occurred_at',
                'payload',
                'meta',
                'status',
                'available_at',
                'claim_token',
                'claim_expires_at',
                'attempts',
                'last_attempted_at',
                'published_at',
                'last_error',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'payload',
                'meta',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'subject_type',
                    'id_column' => 'subject_id',
                    'targets' => [
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
                    ],
                ],
            ],
            'json_path_references' => [
                'payload' => [
                    'workflow_transition.from_contact_status_id' => [
                        'table' => 'contact_statuses',
                    ],
                    'workflow_transition.to_contact_status_id' => [
                        'table' => 'contact_statuses',
                    ],
                ],
            ],
            'null_on_import' => [
                'claim_token',
                'claim_expires_at',
            ],
            'import_value_maps' => [
                'status' => [
                    'pending' => 'paused',
                    'processing' => 'paused',
                ],
            ],
            'resume_items' => [
                [
                    'category' => 'automation_events',
                    'column' => 'status',
                    'statuses' => ['pending', 'processing'],
                ],
            ],
        ],

        'automation_event_consumer_receipts' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'event_id',
                'consumer',
                'completed_at',
                'created_at',
                'updated_at',
            ],
        ],
    ],
];