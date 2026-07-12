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
    | Schedule profiles exclusively own Webinar lifecycle behavior:
    | - timing and exact schedule rules
    | - lifecycle conditions
    | - enablement
    | - Webinar-specific skip behavior
    |
    | Schedule profile items identify the lifecycle slot and reference reusable
    | Messaging templates by stable runtime dimensions. They must not embed
    | reusable subject/body/message copy.
    |
    | Every Webinar lifecycle message dispatched through Webinars must have a
    | matching profile item. Messaging-owned consent-event acknowledgements are
    | not Webinar schedule-profile items.
    |
    | Missing profile coverage must not silently fall back to template timing or
    | an implicit immediate send.
    |
    | Multiple reminder items may share message_type = reminder. Use the item
    | key to identify the Webinar lifecycle slot and message_template_key to
    | identify the reusable Messaging template. source_config_path is optional
    | provenance/debug location only and must not be durable matching identity.
    | Do not create schedule-specific Messaging message types such as
    | reminder_30_minute.
    |
    | `source_version` is numeric. Sync persists numeric versions only; do not
    | use descriptive release strings in this field.
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
                'key' => 'email_confirmation_10_minute_delay',
                'label' => 'Confirmation Email',
                'context_key' => 'confirmations',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'confirmation',
                'source_config_path' => 'messaging.email.transactional.webinar.confirmations.0',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 10,
                ],
                'conditions' => [
                    [
                        'field' => 'webinar.starts_at',
                        'operator' => 'at_least_minutes_from_now',
                        'value' => 40,
                    ],
                ],
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
                'message_template_key' => 'reminder_1_day',
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
                'message_template_key' => 'reminder_30_minute',
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
                'message_template_key' => 'post_attended',
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
