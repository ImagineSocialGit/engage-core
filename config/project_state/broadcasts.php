<?php

return [
    'version' => 1,
    'tables' => [
        'broadcasts' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'user_id',
                'name',
                'channel',
                'purpose',
                'scope',
                'dispatch_key',
                'message_type',
                'payload_class',
                'queue',
                'status',
                'send_at',
                'payload',
                'recipient_filter',
                'recipient_count',
                'scheduled_count',
                'cancelled_at',
                'completed_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'payload',
                'recipient_filter',
                'meta',
            ],
            'null_on_import' => ['user_id'],
            'import_value_maps' => [
                'status' => [
                    'sending' => 'paused',
                ],
            ],
            'resume_items' => [
                [
                    'category' => 'broadcasts',
                    'column' => 'status',
                    'statuses' => ['sending'],
                ],
            ],
        ],

        'broadcast_recipients' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['broadcast_id', 'contact_id', 'id'],
            'columns' => [
                'id',
                'broadcast_id',
                'contact_id',
                'status',
                'scheduled_message_ids',
                'sent_at',
                'terminal_reason',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'scheduled_message_ids',
                'meta',
            ],
            'references' => [
                'broadcast_id' => 'broadcasts',
                'contact_id' => 'contacts',
            ],
        ],
    ],
];