<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webinar Schedule Profiles Template
    |--------------------------------------------------------------------------
    |
    | File path:
    | config/webinars/schedule_profiles.php
    | client/{client-key}/config/webinars/schedule_profiles.php, if client override exists
    |
    | Webinars owns schedule profiles.
    | Messaging owns reusable message copy/templates.
    |
    | Schedule profiles decide when webinar lifecycle messages are sent.
    | Schedule profile items identify the schedule slot and reference Messaging
    | definitions by stable runtime dimensions. They must not embed reusable
    | subject/body/message copy.
    |
    | Multiple reminder items may share message_type = reminder. Use the item
    | key and source_config_path to identify the schedule slot. Do not create
    | schedule-specific Messaging message types such as reminder_30_minute.
    |
    */

    'full_10_day' => [
        'name' => 'Full 10-Day Schedule',
        'description' => 'Confirmation, pre-event reminders, live reminder, waitlist notice, and post-event transactional follow-ups.',
        'status' => 'active',
        'is_default' => true,
        'is_active' => true,
        'source_version' => 1,

        'items' => [
            [
                'key' => 'email_confirmation_15_minute_delay',
                'label' => 'Confirmation Email',
                'context_key' => 'confirmations',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'source_config_path' => 'messaging.email.transactional.webinar.confirmations.0',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 15,
                ],
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 10,
                'meta' => [],
            ],

            [
                'key' => 'email_reminder_1_day_before',
                'label' => '1-Day Reminder Email',
                'context_key' => 'reminders',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'reminder',
                'dispatch_key' => 'registration_created',
                'source_config_path' => 'messaging.email.transactional.webinar.reminders.0',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => -1440,
                ],
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 20,
                'meta' => [],
            ],

            [
                'key' => 'email_live_reminder',
                'label' => 'Live Reminder Email',
                'context_key' => 'reminders',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'reminder',
                'dispatch_key' => 'registration_created',
                'source_config_path' => 'messaging.email.transactional.webinar.reminders.1',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => 5,
                ],
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 30,
                'meta' => [
                    'skip_when_join_clicked' => true,
                ],
            ],

            [
                'key' => 'email_post_attended',
                'label' => 'Attended Replay Follow-Up Email',
                'context_key' => 'post_attended',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'post_attended',
                'dispatch_key' => 'webinar_ended',
                'source_config_path' => 'messaging.email.transactional.webinar.post_attended.0',
                'timing' => 'immediate',
                'schedule' => null,
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 100,
                'meta' => [],
            ],
        ],
    ],

    'no_reminders' => [
        'name' => 'No Reminders',
        'description' => 'Send only confirmation and post-event transactional follow-ups.',
        'status' => 'active',
        'is_default' => false,
        'is_active' => true,
        'source_version' => 1,
        'items' => [
            // Include confirmation and post-event items only.
        ],
    ],

];
