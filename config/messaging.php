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
                'campaigns' => true,
                'permission_invitations' => false,
                'webinar_registrations' => true,
                'webinar_waitlists' => true,
                'internal_notifications' => false,
                'route_send_message_points' => true,
            ],

            'purpose_scopes' => [
                '*' => true,
            ],
        ],
    ],

    'consent' => [
        'require_active_consent' => true,

        /*
        | Generic consent acknowledgements are Messaging-owned system copy.
        | Module-owned consent domains supply the human-readable topic and may
        | override copy per channel/purpose from their own config when needed.
        |
        | These use :client_name and :consent_topic runtime markers rather than
        | Messaging template tokens, so consent acknowledgement copy does not
        | create cross-module token ownership requirements.
        */
        'opt_in_defaults' => [
            'email' => [
                'marketing' => [
                    'subject' => 'You’re subscribed',
                    'body' => 'You’re subscribed to receive marketing emails from :client_name related to :consent_topic. You can unsubscribe at any time.',
                    'queue' => 'opt_in_messages',
                ],
                'transactional' => [
                    'subject' => 'Your email updates are enabled',
                    'body' => 'You’re subscribed to receive email updates from :client_name related to :consent_topic.',
                    'queue' => 'opt_in_messages',
                ],
            ],

            'sms' => [
                'marketing' => [
                    'message' => 'You’re subscribed to receive marketing text messages from :client_name related to :consent_topic. Reply HELP for help. Msg & data rates may apply. Reply STOP to opt out.',
                    'queue' => 'opt_in_messages',
                ],
                'transactional' => [
                    'message' => 'You’re subscribed to receive text message updates from :client_name related to :consent_topic. Reply HELP for help. Msg & data rates may apply. Reply STOP to opt out.',
                    'queue' => 'opt_in_messages',
                ],
            ],
        ],
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
