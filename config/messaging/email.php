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

    'attachments' => [
        'max_file_bytes' => (int) env('EMAIL_ATTACHMENT_MAX_FILE_BYTES', 10485760),
        'max_total_bytes' => (int) env('EMAIL_ATTACHMENT_MAX_TOTAL_BYTES', 15728640),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML presentation
    |--------------------------------------------------------------------------
    |
    | client   = allow the selected client's semantic email view override.
    | standard = render the Core email view directly, bypassing client branding.
    |
    | Surface overrides are intentionally presentation-only. They do not change
    | message content, consent, delivery, timing, or module ownership.
    |
    */
    'presentation' => [
        'default' => 'client',
        'surfaces' => [
            'internal_notifications' => 'standard',
        ],
    ],

    'inbound_domain' => env('INBOUND_EMAIL_DOMAIN'),

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