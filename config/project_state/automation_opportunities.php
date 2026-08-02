<?php

$subjectTargets = [
    'App\\Modules\\Core\\Models\\Contact' => 'contacts',
    'App\\Modules\\InternalNotifications\\Models\\TeamMember' => 'team_members',
    'App\\Modules\\Tasks\\Models\\Task' => 'tasks',
];

return [
    'version' => 1,
    'tables' => [
        'automation_behavior_occurrences' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'action_key',
                'actor_type',
                'actor_id',
                'subject_type',
                'subject_id',
                'capability_key',
                'fingerprint',
                'fingerprint_parts',
                'context',
                'meta',
                'occurred_at',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'fingerprint_parts',
                'context',
                'meta',
            ],
            'null_on_import' => [
                'actor_type',
                'actor_id',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'subject_type',
                    'id_column' => 'subject_id',
                    'targets' => $subjectTargets,
                ],
            ],
        ],

        'automation_opportunities' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'action_key',
                'fingerprint',
                'capability_key',
                'status',
                'occurrence_count',
                'distinct_subject_count',
                'distinct_actor_count',
                'first_occurred_at',
                'last_occurred_at',
                'eligible_at',
                'suggested_at',
                'dismissed_at',
                'dismissed_until',
                'converted_at',
                'invalidated_at',
                'context',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'context',
                'meta',
            ],
        ],
    ],
];