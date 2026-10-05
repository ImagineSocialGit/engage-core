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
];