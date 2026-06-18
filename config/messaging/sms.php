<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS messaging channel
    |--------------------------------------------------------------------------
    |
    | Messaging-level SMS behavior.
    | Transport remains in config/sms.php.
    |
    */

    'consent' => [
        'require_opt_in' => true,
    ],

    'suppression' => [
        'respect_stop_requests' => true,
    ],

    'inbound' => [
        'stop_keywords' => [
            'stop',
            'stopall',
            'unsubscribe',
            'cancel',
            'end',
            'quit',
        ],

        'help_keywords' => [
            'help',
            'info',
        ],

        'stop_response' => 'You have been opted out of SMS messages. Reply START to resubscribe.',
        'help_response' => 'Reply STOP to opt out of SMS messages. Message and data rates may apply.',
    ],

];