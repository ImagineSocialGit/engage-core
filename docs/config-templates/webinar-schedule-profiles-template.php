<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webinar Schedule Profiles Template
    |--------------------------------------------------------------------------
    |
    | Core config location:
    | config/webinars.php
    |     -> schedule_profiles
    |
    | Client override location:
    | client/{client-key}/config/webinars/schedule_profiles.php
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
    | matching profile item. Messaging-owned consent acknowledgements are not
    | Webinar schedule-profile items; they resolve through Messaging consent
    | domains.
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
    | Supported generic schedule shapes:
    |
    | delay
    |     ['type' => 'delay', 'minutes' => 10]
    |
    | anchored
    |     ['type' => 'anchored', 'minutes' => -1440]
    |
    | next_day_at
    |     ['type' => 'next_day_at', 'time' => '09:00']
    |
    | next_day_at uses config('client.timezone'), with application timezone
    | fallback. Do not duplicate timezone in each schedule item.
    |
    | MessageSendTimeResolver uses the supplied anchor when present, otherwise
    | triggeredAt, as the calendar-day base for next_day_at. Webinar post-event
    | follow-ups pass webinar.ends_at as the anchor so delayed provider webhook
    | processing does not shift the intended next-morning date.
    |
    | Profile-owned conditions are checked when the message is planned. Resolved
    | conditions are also persisted with ScheduledMessage metadata and rechecked
    | by ScheduledMessageGate immediately before provider delivery.
    |
    | `source_version` is numeric. Sync persists numeric versions only; do not
    | use descriptive release strings in this field.
    |
    | Core should keep its default Webinar cadence small and vertical-neutral.
    | Rich client-specific cadences belong in client config. Numeric/list arrays
    | replace default lists when present, so client reminder/profile item lists do
    | not append duplicate Core reminder slots.
    |
    */

    'standard_webinar' => [
        'name' => 'Standard Webinar Schedule',
        'description' => 'Confirmation, 7-day/24-hour/30-minute/live reminders, and post-event transactional follow-up.',
        'status' => 'active',
        'is_default' => true,
        'is_active' => true,
        'source_version' => 1,

        'items' => [
            [
                'key' => 'email_confirmation',
                'label' => 'Email registration confirmation',
                'context_key' => 'confirmations',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'confirmation',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmations.0',
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
                'sort_order' => 100,
                'meta' => [],
            ],

            [
                'key' => 'email_reminder_1_week',
                'label' => 'Email 7-day reminder',
                'context_key' => 'reminders',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'reminder',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'reminder_1_week',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.0',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => -10080,
                ],
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 110,
                'meta' => [],
            ],

            [
                'key' => 'email_reminder_1_day',
                'label' => 'Email 24-hour reminder',
                'context_key' => 'reminders',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'reminder',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'reminder_1_day',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.1',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => -1440,
                ],
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 120,
                'meta' => [],
            ],

            [
                'key' => 'email_reminder_30_minute',
                'label' => 'Email 30-minute reminder',
                'context_key' => 'reminders',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'reminder',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'reminder_30_minute',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.2',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => -30,
                ],
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 130,
                'meta' => [],
            ],

            [
                'key' => 'email_reminder_live',
                'label' => 'Email live-now reminder',
                'context_key' => 'reminders',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'reminder',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'reminder_live',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.3',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => 0,
                ],
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 140,
                'meta' => [
                    'skip_when_join_clicked' => true,
                ],
            ],

            [
                'key' => 'email_post_attended',
                'label' => 'Email attended replay follow-up',
                'context_key' => 'post_attended',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'post_attended',
                'dispatch_key' => 'webinar_ended',
                'message_template_key' => 'post_attended',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.post_attended.0',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'next_day_at',
                    'time' => '09:00',
                ],
                'conditions' => [
                    [
                        'field' => 'webinar_registration.attended_at',
                        'operator' => 'filled',
                    ],
                    [
                        'field' => 'webinar.playback_url',
                        'operator' => 'filled',
                    ],
                ],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 200,
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
