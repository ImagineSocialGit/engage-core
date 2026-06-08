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

];