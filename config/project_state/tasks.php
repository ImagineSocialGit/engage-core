<?php

return [
    'version' => 1,
    'tables' => [
        'task_templates' => [
            'mode' => 'upsert',
            'identity' => ['key'],
            'preserve_id' => false,
            'order_by' => ['key'],
            'columns' => [
                'id',
                'key',
                'source',
                'source_version',
                'owner_group',
                'category',
                'name',
                'title',
                'description',
                'task_description',
                'assigned_to_type',
                'assigned_to_id',
                'assigned_to_strategy',
                'responsible_party',
                'responsible_type',
                'responsible_id',
                'priority',
                'due_offset_minutes',
                'link_defaults',
                'defaults',
                'is_active',
                'is_customized',
                'customized_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'link_defaults',
                'defaults',
                'meta',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'assigned_to_type',
                    'id_column' => 'assigned_to_id',
                    'targets' => [
                        'App\\Modules\\Core\\Models\\Contact' => 'contacts',
                        'App\\Modules\\InternalNotifications\\Models\\TeamMember' => 'team_members',
                    ],
                ],
                [
                    'type_column' => 'responsible_type',
                    'id_column' => 'responsible_id',
                    'targets' => [
                        'App\\Modules\\Core\\Models\\Contact' => 'contacts',
                        'App\\Modules\\InternalNotifications\\Models\\TeamMember' => 'team_members',
                    ],
                ],
            ],
        ],

        'tasks' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'assigned_to_type',
                'assigned_to_id',
                'responsible_party',
                'responsible_type',
                'responsible_id',
                'task_template_id',
                'task_template_key',
                'source',
                'title',
                'description',
                'status',
                'priority',
                'due_at',
                'completed_at',
                'canceled_at',
                'canceled_reason',
                'archived_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'task_template_id' => 'task_templates',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'assigned_to_type',
                    'id_column' => 'assigned_to_id',
                    'targets' => [
                        'App\\Modules\\Core\\Models\\Contact' => 'contacts',
                        'App\\Modules\\InternalNotifications\\Models\\TeamMember' => 'team_members',
                    ],
                ],
                [
                    'type_column' => 'responsible_type',
                    'id_column' => 'responsible_id',
                    'targets' => [
                        'App\\Modules\\Core\\Models\\Contact' => 'contacts',
                        'App\\Modules\\InternalNotifications\\Models\\TeamMember' => 'team_members',
                    ],
                ],
            ],
        ],

        'task_links' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['task_id', 'role', 'id'],
            'columns' => [
                'id',
                'task_id',
                'linkable_type',
                'linkable_id',
                'role',
                'created_at',
                'updated_at',
            ],
            'references' => [
                'task_id' => 'tasks',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'linkable_type',
                    'id_column' => 'linkable_id',
                    'targets' => [
                        'App\\Modules\\Core\\Models\\Contact' => 'contacts',
                        'App\\Modules\\Webinars\\Models\\WebinarSeries' => 'webinar_series',
                        'App\\Modules\\Webinars\\Models\\Webinar' => 'webinars',
                        'App\\Modules\\Webinars\\Models\\WebinarRegistration' => 'webinar_registrations',
                        'App\\Modules\\Webinars\\Models\\WebinarWaitlistSignup' => 'webinar_waitlist_signups',
                        'App\\Modules\\Scheduling\\Models\\Appointment' => 'appointments',
                    ],
                ],
            ],
        ],
    ],
];