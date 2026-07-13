<?php

use App\Modules\Messaging\Payloads\SmsPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Messaging Template
    |--------------------------------------------------------------------------
    |
    | File path pattern:
    | config/messaging/sms/definitions/{purpose}/{scope}.php
    | client/{client-key}/config/messaging/sms/definitions/{purpose}/{scope}.php
    |
    | Create one file per purpose/scope pair.
    |
    | Reusable Messaging templates own content and delivery-template metadata.
    | They must not own business timing, lifecycle conditions, sequencing,
    | dependencies, enablement, or module-specific skip behavior.
    |
    | Consuming modules resolve those concerns from their own records/state,
    | then combine the selected reusable template with already-resolved behavior
    | through ResolvedMessageDispatchBuilder.
    |
    | A reusable template must never silently become an immediate message merely
    | because module-owned behavior is missing.
    |
    | Runtime callers must provide exact sendAt or explicit caller-owned behavior.
    | Module-owned dispatch paths should also provide stable logical occurrenceKey
    | identity for retries/idempotency; send_at is not logical occurrence identity.

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
    | Consent acknowledgements are not authored as per-scope `opt_ins` groups in
    | reusable Webinar definition files. Messaging resolves them through
    | ConsentDomainRegistry + ConsentOptInDefinitionResolver using generic
    | Messaging copy, a human-readable consent topic, and optional module/client
    | overrides.
    |
    | Campaign SMS templates use the same structure as email campaign templates:
    |
    | campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}
    |
    | Campaign SMS templates resolve by channel + purpose + scope + campaign_key
    | + step_number + campaign_step_variant_key.
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
    | Validation and authoring reuse
    |--------------------------------------------------------------------------
    |
    | SMS definitions participate in the same reusable Messaging validation
    | infrastructure as email. Validate definition shape, payload requirements,
    | registered dispatch keys, context-aware fields/tokens, forbidden template-owned
    | lifecycle behavior fields, and channel/purpose/scope compatibility before
    | client handoff. MessageTemplateTokenValidator is the shared context-aware
    | authority used by config validation, preset sync, and CRM template editing.
    |
    | UI visibility is separate from runtime capability. A configured SMS template
    | may be valid while the channel remains hidden for a client surface; a route or
    | preset that requires an unavailable executable SMS path should be reported
    | according to whether intended runtime behavior is impossible or merely dormant.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | transactional:webinar example shape
    |--------------------------------------------------------------------------
    */

    'confirmations' => [
        [
            'key' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_type' => 'confirmation',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'payload_class' => SmsPayload::class,
            'queue' => 'confirmation_messages',

            'payload' => [
                'message' => 'You’re registered for {webinar_title}. Join here: {webinar_join_url}',
            ],
        ],
    ],

    'reminders' => [
        [
            'key' => 'reminder_1_week',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'payload' => [
                'message' => '{webinar_title} is one week away on {webinar_start_date} at {webinar_start_time}.',
            ],
        ],

        [
            'key' => 'reminder_1_day',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'payload' => [
                'message' => '{webinar_title} is tomorrow at {webinar_start_time}. Join: {webinar_join_url}',
            ],
        ],

        [
            'key' => 'reminder_30_minute',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'payload' => [
                'message' => '{webinar_title} starts in 30 minutes. Join: {webinar_join_url}',
            ],
        ],

        [
            'key' => 'reminder_live',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',

            'payload' => [
                'message' => '{webinar_title} is live now. Join: {webinar_join_url}',
            ],
        ],
    ],

    'post_attended' => [
        [
            'key' => 'post_attended',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_attended',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'payload_class' => SmsPayload::class,
            'queue' => 'post_event',

            'payload' => [
                'message' => 'Thanks for joining {webinar_title}. Replay: {webinar_playback_url}',
            ],
        ],
    ],

    'post_missed' => [
        [
            'key' => 'post_missed',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_missed',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',

            'payload_class' => SmsPayload::class,
            'queue' => 'post_event',

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
                    'variants' => [
                        'sms' => [
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
        ],
    ],

];
