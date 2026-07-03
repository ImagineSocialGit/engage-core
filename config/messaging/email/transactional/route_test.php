<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Disposable Route Test Email Templates
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
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'route_test',

            'timing' => 'scheduled',
            'payload_class' => EmailPayload::class,
            'queue' => 'emails',

            'schedule' => [
                'type' => 'delay',
                'minutes' => 1,
            ],

            'payload' => [
                'subject' => 'Smoke test: task done',
                'body' => 'Hey {first_name}, task done.',
            ],
        ],
    ],

];
