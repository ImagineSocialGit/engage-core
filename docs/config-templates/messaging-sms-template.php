<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Messaging Template
    |--------------------------------------------------------------------------
    |
    | File path pattern:
    | config/messaging/sms/{purpose}/{scope}.php
    | client/{client-key}/config/messaging/sms/{purpose}/{scope}.php
    |
    | Create one file per purpose/scope pair.
    |
    | Keep SMS payloads short.
    | SMS should be supplemental and safe to skip if consent, suppression,
    | provider, or recipient-phone requirements are not satisfied.
    |
    | SMS capability may exist while SMS is hidden from client/admin UI.
    | UI exposure should come from Messaging channel availability, not from
    | raw provider config.
    |
    | Email is the primary implementation path for most workflows.
    | Mirror SMS only after the email config path passes.
    |
    | Campaign SMS templates use the same structure as email campaign templates:
    |
    | campaigns.{campaign_key}.steps.{step_number}
    |
    | Webinar SMS reminder definitions should not invent schedule-specific
    | message types such as reminder_30_minute. Use the canonical reminders
    | array; Webinars schedule profiles own timing/slot identity.
    |
    | Campaign presets own timing. Campaign SMS templates own only delivery
    | template fields such as payload_class, queue, and payload.
    |
    | Regular SMS Broadcasts usually provide ad hoc payloads inline from the
    | Broadcast record. SMS Broadcast payloads use payload.message and are
    | hydrated by Messaging with to/channel/purpose/scope/message_type before
    | scheduling.
    */

    /*
    |--------------------------------------------------------------------------
    | transactional:webinar example shape
    |--------------------------------------------------------------------------
    */

    'confirmations' => [
        [
            'dispatch_key' => 'registration_created',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'confirmation_messages',

            'schedule' => [
                'type' => 'delay',
                'minutes' => 15,
            ],

            'payload' => [
                'message' => 'You’re registered for {webinar_title}. Join here: {webinar_join_url}',
            ],
        ],
    ],

    'reminders' => [
        [
            'dispatch_key' => 'registration_created',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => -30,
            ],

            'payload' => [
                'message' => '{webinar_title} starts in 30 minutes. Join: {webinar_join_url}',
            ],
        ],

        [
            'dispatch_key' => 'registration_created',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'skip_when_join_clicked' => true,
            'timing' => 'scheduled',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'schedule' => [
                'type' => 'anchored',
                'minutes' => 5,
            ],

            'payload' => [
                'message' => '{webinar_title} is live. Join now: {webinar_join_url}',
            ],
        ],
    ],

    'post_attended' => [
        [
            'dispatch_key' => 'webinar_ended',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'immediate',
            'payload_class' => SmsPayload::class,
            'queue' => 'post_event',

            'conditions' => [
                [
                    'field' => 'webinar_registration.attended_at',
                    'operator' => 'filled',
                ],
            ],

            'payload' => [
                'message' => 'Thanks for joining {webinar_title}. Replay: {webinar_playback_url}',
            ],
        ],
    ],

    'post_missed' => [
        [
            'dispatch_key' => 'webinar_ended',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'timing' => 'immediate',
            'payload_class' => SmsPayload::class,
            'queue' => 'post_event',

            'conditions' => [
                [
                    'field' => 'webinar_registration.attended_at',
                    'operator' => 'blank',
                ],
            ],

            'payload' => [
                'message' => 'Sorry we missed you at {webinar_title}. Replay: {webinar_playback_url}',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | marketing:broadcast note
    |--------------------------------------------------------------------------
    |
    | Regular SMS Broadcasts are single-channel ad hoc sends. The Broadcast
    | stores payload.message, then passes an inline Messaging definition with
    | dispatch_key = broadcast_send. Messaging resolves the Contact phone into
    | the scheduled payload's to field.
    |
    | If a Contact has no usable phone, Messaging schedules nothing for that
    | recipient and Broadcast bookkeeping records the recipient as skipped.
    |
    | If a future feature intentionally dispatches reusable Broadcast-style SMS
    | from config, it should use purpose = marketing, scope = broadcast,
    | dispatch_key = broadcast_send, and SmsPayload::class.
    */

    /*
    |--------------------------------------------------------------------------
    | marketing:webinar_nurture campaign template example
    |--------------------------------------------------------------------------
    */

    'campaigns' => [
        'webinar_attended_nurture' => [
            'steps' => [
                1 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'message' => 'Hi {first_name}, thanks again for joining. Reply with your biggest question.',
                    ],
                ],
            ],
        ],
    ],

];


