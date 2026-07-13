<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [
    'confirmations' => [
        [
            'key' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_type' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'payload' => [
                'subject' => 'You’re registered for {webinar_title}',
                'body' => <<<'TEXT'
Hi {first_name},

You’re registered for {webinar_title}.

Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}

We look forward to seeing you there.
TEXT,
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
                'secondary_link' => [
                    'label' => 'Need to cancel your registration?',
                    'url' => '{cancel_registration_url}',
                ],
            ],
        ],
    ],


    'reminders' => [
        [
            'key' => 'reminder_1_week',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => '{webinar_title} is one week away',
                'body' => <<<'TEXT'
Hi {first_name},

Just a reminder that {webinar_title} is one week away.

Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}
TEXT,
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
        [
            'key' => 'reminder_1_day',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => '{webinar_title} is tomorrow',
                'body' => <<<'TEXT'
Hi {first_name},

Just a reminder that {webinar_title} is tomorrow.

Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}
TEXT,
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
        [
            'key' => 'reminder_30_minute',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => '{webinar_title} starts in 30 minutes',
                'body' => <<<'TEXT'
Hi {first_name},

{webinar_title} starts in 30 minutes.

Time: {webinar_start_time}

{cta}
TEXT,
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
        [
            'key' => 'reminder_live',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => '{webinar_title} is live now',
                'body' => <<<'TEXT'
Hi {first_name},

{webinar_title} is live now.

{cta}
TEXT,
                'cta' => [
                    'label' => 'Join Now',
                    'url' => '{webinar_join_url}',
                ],
            ],
        ],
    ],

    'post_attended' => [
        [
            'key' => 'post_attended',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_attended',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',
            'payload' => [
                'subject' => 'Thanks for joining {webinar_title}',
                'body' => <<<'TEXT'
Hi {first_name},

Thanks for joining {webinar_title}.

You can watch the replay here:

{cta}

We hope the session was useful.
TEXT,
                'cta' => [
                    'label' => 'Watch Replay',
                    'url' => '{webinar_playback_url}',
                ],
            ],
        ],
    ],

    'post_missed' => [
        [
            'key' => 'post_missed',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_missed',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',
            'payload' => [
                'subject' => 'Sorry we missed you at {webinar_title}',
                'body' => <<<'TEXT'
Hi {first_name},

Sorry we missed you at {webinar_title}.

You can watch the replay here:

{cta}
TEXT,
                'cta' => [
                    'label' => 'Watch Replay',
                    'url' => '{webinar_playback_url}',
                ],
            ],
        ],
    ],
];
