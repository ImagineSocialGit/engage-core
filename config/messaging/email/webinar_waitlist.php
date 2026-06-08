<?php

use App\Messaging\Payloads\Webinars\Email\WebinarWaitlistScheduledEmailPayload;

return [

    'scheduled' => [
        'enabled' => true,
        'scope' => 'webinar_waitlist',
        'purpose' => 'marketing',
        'message_type' => 'webinar_waitlist_scheduled',
        'payload_class' => WebinarWaitlistScheduledEmailPayload::class,
        'queue' => 'notifications',
    ],

];