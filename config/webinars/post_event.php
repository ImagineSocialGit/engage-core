<?php

use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\EnsureWebinarPostEventReviewAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;
use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarPlaybackAction;

return [
    'events' => [
        'webinar.ended' => [
            EnsureWebinarPostEventReviewAction::class,
            RecordWebinarProviderAttendanceAction::class,
            DispatchPostWebinarFollowUpsAction::class,
        ],

        'webinar.recording_completed' => [
            EnsureWebinarPostEventReviewAction::class,
            RecordWebinarProviderAttendanceAction::class,
            ResolveWebinarPlaybackAction::class,
            DispatchPostWebinarFollowUpsAction::class,
        ],
    ],

    'retry_seconds' => 60,

    'attendance' => [
        'enabled' => true,
    ],

    'recordings' => [
        'enabled' => true,
    ],

    'review' => [
        'required' => false,
    ],

    'future_availability_subscription' => [
        'enabled' => false,
        'duration_days' => 365,
        'notification_lead_days' => 0,
        'channels' => [],
    ],

    'outcome_messages' => [
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
    ],
];