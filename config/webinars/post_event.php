<?php

use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;
use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarPlaybackAction;

return [
    'events' => [
        'webinar.ended' => [
            RecordWebinarProviderAttendanceAction::class,
        ],

        'webinar.recording_completed' => [
            ResolveWebinarPlaybackAction::class,
            DispatchPostWebinarFollowUpsAction::class,
        ],
    ],

    'retry_seconds' => 60,

    'attendance' => [
        'enabled' => true,
        'empty_records_retry_for_minutes' => 15,
    ],

    'recordings' => [
        'enabled' => true,
    ],

    'outcome_messages' => [
        'enabled' => true,
        'dispatch_key' => 'webinar_ended',
        'purpose' => 'transactional',
        'scope' => 'webinar',
        'channels' => [
            'email',
            'sms',
        ],

        'conditions' => [
            [
                'field' => 'webinar.playback_url',
                'operator' => 'filled',
            ],
        ],
    ],

    'automation_events' => [
        'enabled' => true,

        'webinar_ended' => [
            'event_key' => 'webinar.ended',
        ],

        'attended' => [
            'event_key' => 'webinar.attended',
        ],

        'missed' => [
            'event_key' => 'webinar.missed',
        ],

        'replay_available' => [
            'event_key' => 'webinar.replay_available',
        ],
    ],
];
