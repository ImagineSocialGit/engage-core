<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    'alerts' => [
        [
            'key' => 'alert',
            'dispatch_key' => 'webinar_added',
            'message_type' => 'alert',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',

            'payload' => [
                'subject' => 'New webinar scheduled: {webinar_title}',
                'body' => 'A new webinar session is available. Register here: {webinar_registration_url}',
                'cta' => [
                    'label' => 'Register Now',
                    'url' => '{webinar_registration_url}',
                ],
            ],
        ],
    ],


];
