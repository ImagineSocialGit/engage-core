<?php

return [

    'email' => [

        'provider' => env('EMAIL_PROVIDER', 'resend'),

        'providers' => [
            'resend' => [
                'provider' => App\Integrations\Messaging\Email\Resend\ResendEmailProvider::class,
                'webhook_handler' => App\Integrations\Messaging\Email\Resend\ResendWebhookHandler::class,
            ],
        ],

        'recipient_models' => [
            App\Models\Contact::class,
        ],

        'unsubscribe' => [

            'signed_url_expiration_days' => env(
                'EMAIL_UNSUBSCRIBE_SIGNED_URL_EXPIRATION_DAYS',
                30
            ),

        ],

        'transactional_opt_out' => [

            'signed_url_expiration_days' => env(
                'EMAIL_TRANSACTIONAL_OPT_OUT_SIGNED_URL_EXPIRATION_DAYS',
                30
            ),

        ],

    ],

    'sms' => [

        'consent' => [
            'require_opt_in' => true,
        ],

        'suppressions' => [
            'respect_stop_requests' => true,
        ],

    ],

];