<?php

return [

    'email' => [

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

    'webhooks' => [

        'sms' => [

            'twilio' => [

                'stop_keywords' => [
                    'stop',
                    'unsubscribe',
                    'cancel',
                    'end',
                    'quit',
                    'stopall',
                    'revoke',
                    'optout',
                ],

                'start_keywords' => [
                    'start',
                    'unstop',
                ],

                'help_keywords' => [
                    'help',
                    'info',
                ],

            ],

        ],

        'email' => [

            'resend' => [

                'suppression_events' => [
                    'email.bounced',
                    'email.complained',
                ],

                'revocation_events' => [
                    'email.unsubscribed',
                ],

            ],

        ],

    ],

];