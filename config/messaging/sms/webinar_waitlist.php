<?php

use App\Messaging\Payloads\Webinars\Sms\WebinarWaitlistScheduledSmsPayload;

return [

    'scheduled' => [
        'enabled' => true,
        'scope' => 'webinar_waitlist',
        'purpose' => 'marketing',
        'message_type' => 'webinar_waitlist_scheduled',
        'payload_class' => WebinarWaitlistScheduledSmsPayload::class,
        'queue' => 'notifications',
    ],

];