<?php

return [
    'presentation' => [
        'brand_name' => null,
        'surface_label' => 'Portal',
        'logo' => null,
        'logo_url' => null,
        'primary_color' => null,
        'accent_color' => null,
        'surface_color' => null,
        'background_color' => null,
        'robots' => 'noindex,nofollow',
    ],

    'login' => [
        'max_attempts' => (int) env('PORTAL_LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('PORTAL_LOGIN_DECAY_SECONDS', 60),
    ],

    'notifications' => [
        'portal_invitation' => [
            'email' => [
                'subject' => 'Activate your account',
                'body' => "Use the secure link below to activate your account.\n\n{action_url}",
                'action_label' => 'Activate account',
            ],
            'sms' => [
                'message' => 'Activate your account: {action_url}',
            ],
        ],
        'portal_email_verification' => [
            'email' => [
                'subject' => 'Verify your email',
                'body' => "Use the secure link below to verify your email address.\n\n{action_url}",
                'action_label' => 'Verify email',
            ],
        ],
        'portal_password_reset' => [
            'email' => [
                'subject' => 'Reset your password',
                'body' => "Use the secure link below to choose a new password.\n\n{action_url}",
                'action_label' => 'Reset password',
            ],
        ],
    ],
];