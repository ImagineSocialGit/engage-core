<?php

return [

    'webinars' => [
        'enabled' => true,
        'queue' => 'notifications',
        'schedule' => [
            '10_days',
            '7_days',
            '24_hours',
            '30_minutes',
            '10_minutes',
        ],
        'reminder_offsets' => [
            '10_days' => 14400,
            '7_days' => 10080,
            '24_hours' => 1440,
            '30_minutes' => 30,
            '10_minutes' => 10,
        ],
    ],

    'appointments' => [
        //
    ],

    'los_deadlines' => [
        //
    ],

];