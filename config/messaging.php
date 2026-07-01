<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-channel messaging behavior
    |--------------------------------------------------------------------------
    */

    'recipient_models' => [
        App\Modules\Core\Models\Contact::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel availability
    |--------------------------------------------------------------------------
    |
    | This controls channel availability for UI/admin/client surfaces.
    |
    | runtime_supported means the code/runtime can handle the channel.
    | provider_enabled means a provider may be used for actual transport.
    | surfaces controls whether the channel appears in a specific UI surface.
    | purpose_scopes can restrict a channel to specific purpose/scope pairs.
    |
    | Hiding a channel here must not remove backend protections such as consent
    | gates, suppressions, revocations, STOP/HELP handling, or provider guards.
    |
    */

    'channel_availability' => [
        'email' => [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,

            'surfaces' => [
                'broadcasts' => true,
                'campaigns' => true,
                'permission_invitations' => true,
                'webinar_registrations' => true,
                'webinar_waitlists' => true,
                'internal_notifications' => true,
                'route_send_message_points' => true,
            ],

            'purpose_scopes' => [
                '*' => true,
            ],
        ],

        'sms' => [
            'runtime_supported' => true,
            'provider_enabled' => env('SMS_ENABLED', false),
            'requires_explicit_opt_in' => true,

            'surfaces' => [
                'broadcasts' => false,
                'campaigns' => false,
                'permission_invitations' => false,
                'webinar_registrations' => false,
                'webinar_waitlists' => false,
                'internal_notifications' => false,
                'route_send_message_points' => false,
            ],

            'purpose_scopes' => [
                '*' => true,
            ],
        ],
    ],

    'consent' => [
        'require_active_consent' => true,
    ],

    'suppression' => [
        'enabled' => true,
    ],

    'scheduling' => [
        'dedupe_enabled' => true,
    ],

    'internal_notifications' => [

        'email' => [
            'from_address' => env('INTERNAL_NOTIFICATION_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
            'from_name' => env('INTERNAL_NOTIFICATION_FROM_NAME', env('MAIL_FROM_NAME', config('app.name'))),
        ],

        'sms' => [
            'from' => env('TELNYX_FROM_NOTIFICATIONS'),
        ],

        'inbound_replies' => [
            'default_team_member_email' => env('INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL'),
        ],
    ],

    'inbound' => [
        'handlers' => [
            'sms' => [
                'consent_revocation' => [
                    App\Modules\InboundMessaging\Actions\Sms\Inbound\RevokeSmsConsentFromInboundMessageAction::class,
                ],

                'help' => [
                    App\Modules\InboundMessaging\Actions\Sms\Inbound\RespondToSmsHelpInboundMessageAction::class,
                ],

                'normal_reply' => [],
            ],
        ],
    ],

];