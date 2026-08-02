<?php

return [
    'version' => 1,
    'tables' => [
        'contact_workflow_profiles' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'contact_id',
                'contact_status_id',
                'assigned_to_type',
                'assigned_to_id',
                'last_status_changed_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'contact_id' => 'contacts',
                'contact_status_id' => 'contact_statuses',
            ],
            'json_path_references' => [
                'meta' => [
                    'last_status_change.from_contact_status_id' => [
                        'table' => 'contact_statuses',
                    ],
                    'last_status_change.to_contact_status_id' => [
                        'table' => 'contact_statuses',
                    ],
                ],
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'assigned_to_type',
                    'id_column' => 'assigned_to_id',
                    'targets' => [
                        'App\\Modules\\Core\\Models\\Contact' => 'contacts',
                        'App\\Modules\\InternalNotifications\\Models\\TeamMember' => 'team_members',
                        'App\\Models\\User' => 'users',
                    ],
                ],
            ],
        ],
    ],
];