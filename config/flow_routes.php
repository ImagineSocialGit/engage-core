<?php

return [
    'execution' => [
        /*
         * Maximum number of immediately advancing points handled in one
         * process slice. Active progress is persisted and continued through
         * the queue when this budget is exhausted.
         */
        'immediate_execution_budget' => (int) env('FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET', 25),

        'continuation_queue' => env('FLOW_ROUTE_CONTINUATION_QUEUE', 'default'),
    ],
];