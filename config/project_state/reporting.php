<?php

return [
    'version' => 1,
    'optional' => true,
    'activation_tables' => [
        'reporting_sessions',
        'reporting_observations',
        'reporting_daily_metrics',
        'reporting_projection_checkpoints',
    ],
    'tables' => [
        'reporting_daily_metrics' => [
            'mode' => 'upsert',
            'identity' => [
                'metric_date',
                'metric_key',
                'metric_version',
                'dimension_hash',
            ],
            'preserve_id' => false,
            'order_by' => [
                'metric_date',
                'metric_key',
                'metric_version',
                'dimension_hash',
            ],
            'columns' => [
                'id',
                'metric_date',
                'metric_key',
                'metric_version',
                'dimension_hash',
                'dimensions',
                'numerator',
                'denominator',
                'projected_through',
                'created_at',
                'updated_at',
            ],
            'json_columns' => [
                'dimensions',
            ],
        ],
    ],
];