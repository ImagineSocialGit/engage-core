<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Disposable Route Test SMS Templates
    |--------------------------------------------------------------------------
    |
    | Purpose/scope:
    |
    | transactional:route_test
    |
    | This file is intentionally temporary for FlowRoute smoke testing.
    |
    */

    'task_done' => [
        [
            'dispatch_key' => 'flow_route_task_done',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'route_test',

            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'notifications',

            'schedule' => [
                'type' => 'delay',
                'minutes' => 1,
            ],

            'payload' => [
                'message' => 'Hey, task done.',
            ],
        ],
    ],

];
