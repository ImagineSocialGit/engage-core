<?php

return [
    'version' => 1,
    'tables' => [
        'team_members' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'user_id',
                'name',
                'email',
                'phone',
                'role',
                'is_active',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'null_on_import' => ['user_id'],
        ],

        'team_member_notification_preferences' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => [
                'team_member_id',
                'channel',
                'purpose',
                'scope',
                'id',
            ],
            'columns' => [
                'id',
                'team_member_id',
                'channel',
                'purpose',
                'scope',
                'is_enabled',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'team_member_id' => 'team_members',
            ],
        ],
    ],
];