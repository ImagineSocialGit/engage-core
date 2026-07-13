<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [

    'alerts' => [
        [
            'key' => 'alert',
            'dispatch_key' => 'webinar_added',
            'message_type' => 'alert',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'payload_class' => SmsPayload::class,
            'queue' => 'notifications',

            'payload' => [
                'message' => 'A new webinar has been scheduled for {webinar_title}. Register here: {webinar_registration_url}',
            ],
        ],
    ],


];
