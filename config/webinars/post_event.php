<?php

use App\Actions\Webinars\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Actions\Webinars\PostEvent\RecordWebinarProviderAttendanceAction;

return [
    'events' => [
        'webinar.ended' => [
            RecordWebinarProviderAttendanceAction::class,
            DispatchPostWebinarFollowUpsAction::class,
        ],
    ],

    'attendance' => [
        'enabled' => true,
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