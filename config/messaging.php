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