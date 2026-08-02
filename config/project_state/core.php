<?php

return [
    'version' => 1,
    'tables' => [
        'contact_statuses' => [
            'mode' => 'upsert',
            'identity' => ['key'],
            'preserve_id' => false,
            'order_by' => ['key'],
            'columns' => [
                'id',
                'key',
                'name',
                'description',
                'category',
                'color',
                'is_core',
                'is_active',
                'is_customized',
                'customized_at',
                'sort_order',
                'source_version',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
        ],

        'contact_import_batches' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'name',
                'source',
                'original_filename',
                'status',
                'imported_at',
                'contact_count',
                'successful_count',
                'failed_count',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
        ],

        'contacts' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'source',
                'subsource',
                'contact_import_batch_id',
                'last_contacted_at',
                'last_activity_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'contact_import_batch_id' => 'contact_import_batches',
            ],
        ],

        'contact_tags' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'contact_id',
                'tag',
                'created_at',
                'updated_at',
            ],
            'references' => [
                'contact_id' => 'contacts',
            ],
        ],

        'notes' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'contact_id',
                'related_type',
                'related_id',
                'body',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'references' => [
                'contact_id' => 'contacts',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'related_type',
                    'id_column' => 'related_id',
                    'targets' => [],
                ],
            ],
        ],

        'site_settings' => [
            'mode' => 'upsert',
            'identity' => ['key'],
            'preserve_id' => false,
            'order_by' => ['key'],
            'columns' => [
                'id',
                'key',
                'value',
                'created_at',
                'updated_at',
            ],
        ],
    ],
];