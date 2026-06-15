<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email messaging channel
    |--------------------------------------------------------------------------
    |
    | Messaging-level email configuration.
    | Transport stays in config/mail.php.
    |
    */

    'provider' => env('EMAIL_PROVIDER', 'resend'),

    'from' => [
        'transactional' => [
            'address' => env('FROM_EMAIL_TRANSACTIONAL', env('MAIL_FROM_ADDRESS')),
            'name' => env('FROM_NAME_TRANSACTIONAL', env('MAIL_FROM_NAME')),
        ],

        'marketing' => [
            'address' => env('FROM_EMAIL_MARKETING', env('MAIL_FROM_ADDRESS')),
            'name' => env('FROM_NAME_MARKETING', env('MAIL_FROM_NAME')),
        ],
    ],

    'providers' => [

        'resend' => [
            'provider' => App\Integrations\Messaging\Email\Resend\ResendEmailProvider::class,

            'from' => [
                'transactional' => [
                    'address' => env('RESEND_FROM_EMAIL_TRANSACTIONAL', env('FROM_EMAIL_TRANSACTIONAL', env('MAIL_FROM_ADDRESS'))),
                    'name' => env('RESEND_FROM_NAME_TRANSACTIONAL', env('FROM_NAME_TRANSACTIONAL', env('MAIL_FROM_NAME'))),
                ],

                'marketing' => [
                    'address' => env('RESEND_FROM_EMAIL_MARKETING', env('FROM_EMAIL_MARKETING', env('MAIL_FROM_ADDRESS'))),
                    'name' => env('RESEND_FROM_NAME_MARKETING', env('FROM_NAME_MARKETING', env('MAIL_FROM_NAME'))),
                ],
            ],

            'webhook_handler' =>
                App\Integrations\Messaging\Email\Resend\ResendWebhookHandler::class,
        ],

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

];