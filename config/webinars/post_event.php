<?php

use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;

return [
    'events' => [
        'webinar.ended' => [
            RecordWebinarProviderAttendanceAction::class,
            DispatchPostWebinarFollowUpsAction::class,
        ],
    ],

    'retry_seconds' => 60,

    'attendance' => [
        'enabled' => true,
        'empty_records_retry_for_minutes' => 15,
    ],

    'recordings' => [
        'enabled' => false,
    ],

    'outcome_messages' => [
        'enabled' => true,
        'dispatch_key' => 'webinar_ended',

        'routes' => [
            'attended' => [
                'enabled' => true,
                'conditions' => [
                    [
                        'field' => 'registration.attended_at',
                        'operator' => 'filled',
                    ],
                ],
            ],

            'missed' => [
                'enabled' => true,
                'conditions' => [
                    [
                        'field' => 'registration.attended_at',
                        'operator' => 'blank',
                    ],
                ],
            ],
        ],
    ],
];