<?php

return [
    'branding' => [
        'from_name' => config('brand.name', config('app.name')),
        'logo' => config('brand.logo.path'),
        'footer' => config('brand.footer.text'),
    ],

    'webinars' => [
        'registration_confirmation' => [
            'enabled' => true,
            'subject' => 'You’re registered',
        ],

        'reminder' => [
            'enabled' => true,
            'subject' => 'Your session is coming up',
        ],

        'post_follow_up' => [
            'enabled' => true,
            'subject' => 'Thanks for attending',
        ],

        'waitlist_scheduled' => [
            'enabled' => true,
            'subject' => 'Registration is now open',
        ],
    ],
];