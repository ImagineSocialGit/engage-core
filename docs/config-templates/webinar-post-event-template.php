<?php

use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;
use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarPlaybackAction;

return [

    /*
    |--------------------------------------------------------------------------
    | Webinar Post-Event Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/webinars/post_event.php
    | client/{client-key}/config/webinars/post_event.php
    |
    | This config coordinates post-provider behavior.
    |
    | It does not own email/SMS copy.
    |
    | Transactional replay/follow-up copy belongs in:
    |
    | config/messaging/email/transactional/webinar.php
    | config/messaging/sms/transactional/webinar.php
    |
    | Campaign nurture copy belongs in:
    |
    | config/messaging/email/marketing/webinar_nurture.php
    | config/messaging/sms/marketing/webinar_nurture.php
    |
    | Webinars dispatch transactional follow-ups with:
    |
    | dispatch_key = webinar_ended
    | purpose = transactional
    | scope = webinar
    |
    | Webinars emit automation events after recording domain state. FlowRoutes
    | decides whether those events enroll Campaigns or perform other automation.
    */

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
            'sms'
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
