<?php

return [
    'version' => 3,
    'tables' => [
        'inbound_messages' => [
            'mode' => 'insert_empty',
            'preserve_id' => true,
            'order_by' => ['id'],
            'columns' => [
                'id',
                'sender_type',
                'sender_id',
                'client_key',
                'channel',
                'provider',
                'provider_event_id',
                'provider_message_id',
                'provider_context_id',
                'message_id',
                'from_type',
                'from_value',
                'to_type',
                'to_value',
                'subject',
                'body',
                'classification',
                'purpose',
                'scope',
                'correlated_scheduled_message_id',
                'reply_intent_key',
                'reply_correlation_method',
                'received_at',
                'processed_at',
                'meta',
                'created_at',
                'updated_at',
            ],
            'json_columns' => ['meta'],
            'deferred_references' => [
                'correlated_scheduled_message_id' => 'scheduled_messages',
            ],
            'polymorphic_references' => [
                [
                    'type_column' => 'sender_type',
                    'id_column' => 'sender_id',
                    'targets' => [
                        'App\\Modules\\Core\\Models\\Contact' => 'contacts',
                    ],
                ],
            ],
        ],
    ],
];