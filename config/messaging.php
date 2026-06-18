<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-channel messaging behavior
    |--------------------------------------------------------------------------
    */

    'recipient_models' => [
        App\Models\Contact::class,
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

    'inbound' => [
        'handlers' => [
            'sms' => [
                'consent_revocation' => [
                    App\Actions\Messaging\Sms\Inbound\RevokeSmsConsentFromInboundMessageAction::class,
                ],

                'help' => [
                    App\Actions\Messaging\Sms\Inbound\RespondToSmsHelpInboundMessageAction::class,
                ],

                'normal_reply' => [
                    //
                ],
            ],
        ],
    ],

];