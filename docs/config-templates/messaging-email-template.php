<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Email Messaging Template
    |--------------------------------------------------------------------------
    |
    | File path pattern:
    | config/messaging/email/definitions/{purpose}/{scope}.php
    | client/{client-key}/config/messaging/email/definitions/{purpose}/{scope}.php
    |
    | Webinar scope is set-based. The base webinar.php file must return one
    | explicit default set. Additional sets live at:
    | client/{client-key}/config/messaging/email/definitions/{purpose}/webinar/{set-key}.php
    |
    | Create one file per ordinary purpose/scope pair or Webinar template set.
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
    | Examples:
    | config/messaging/email/definitions/transactional/webinar.php
    | client/[CLIENT_KEY]/config/messaging/email/definitions/transactional/webinar/investor-strategy.php
    | Permission-invitation page/email config is separate:
    | config/messaging/permission_invitations.php
    | config/messaging/email/definitions/marketing/webinar_nurture.php
    | config/messaging/email/definitions/marketing/broadcast.php
    | config/messaging/email/definitions/marketing/mortgage_homebuyer_nurture.php
    | config/messaging/email/definitions/marketing/webinar_waitlist.php
    |
    | Non-campaign top-level keys describe message definition groups. For
    | list-based groups such as confirmations or reminders, the reusable runtime
    | message_type may be singularized, such as confirmation or reminder.
    | Multiple reminder definitions may therefore share message_type = reminder.
    | Every list-based definition must declare a stable explicit key. That key
    | becomes durable DB-owned template identity; source_config_path remains
    | provenance/debug location and may change when list ordering changes.
    |
    | Campaign message templates live under:
    |
    | campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}
    |
    | Campaign templates resolve by channel + purpose + scope + campaign_key
    | + step_number + campaign_step_variant_key.
    |
    | Campaign templates do not own timing, schedule, conditions, strategy,
    | dependencies, or enablement. Campaign steps/variants own that behavior
    | and provide exact resolved dispatch behavior before Messaging schedules.
    |
    | Keep default webinar copy vertical-neutral.
    | Put vertical-specific copy in vertical-specific scopes.
    |
    | Consent acknowledgements are not authored as per-scope `opt_ins` groups in
    | reusable Webinar definition files. Messaging resolves them through
    | ConsentDomainRegistry + ConsentOptInDefinitionResolver using generic
    | Messaging copy, a human-readable consent topic supplied by the owning
    | module/domain, and optional module/client overrides.
    |
    | System markers such as :client_name and :consent_topic belong to the
    | consent acknowledgement resolver. They are not message-template tokens.
    |
    | A Messaging-owned delivery-composition policy may attach compatible
    | acknowledgement intents as immutable ScheduledMessageComponent rows. The
    | acknowledgement inherits the primary message's resolved send time,
    | conditions, queue, and behavior provenance. Any uncovered required
    | acknowledgement needs an explicit standalone fallback.
    |
    | Do not author {delivery_consolidation_*} placement fields. Messaging composes
    | pinned primary and component template versions during payload resolution;
    | no copied fragment or consolidation recipe belongs in message payload/meta.
    |
    | Normal Broadcasts usually provide ad hoc payloads inline from the
    | Broadcast record. Email Broadcast payloads use subject/body. Do not add
    | reusable Broadcast copy here unless a future workflow intentionally
    | dispatches Broadcast messages from Messaging config.
    */

    /*
    |--------------------------------------------------------------------------
    | Validation and authoring reuse
    |--------------------------------------------------------------------------
    |
    | Messaging validation should remain reusable infrastructure, not a one-off
    | CLI-only check. The same context-aware validation sources should support:
    | - setup validation commands;
    | - template save validation;
    | - available-field pickers;
    | - future guided authoring UI;
    | - operator readiness/debug feedback.
    |
    | Validate definition shape, required payload fields, registered dispatch keys,
    | payload classes, channel/purpose/scope compatibility, forbidden template-owned
    | lifecycle behavior fields, and available fields/tokens for the exact runtime
    | context that supplies them. MessageTemplateTokenValidator is the shared
    | context-aware authority used by config validation, preset sync, and CRM
    | template editing.
    |
    | Client-facing field aliases may differ by configured contact noun, but must
    | normalize to canonical Contact fields before runtime validation/rendering.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | transactional:webinar explicit default-set shape
    |--------------------------------------------------------------------------
    */

    'default' => [
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

We’ll send reminders as the event gets closer.
TEXT,
                    'cta' => [
                        'label' => 'Join Webinar',
                        'url' => '{webinar_join_url}',
                    ],
                    'secondary_link' => [
                        'label' => 'Need to cancel?',
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
                    'subject' => 'Your webinar is one week away',
                    'body' => 'Hi {first_name}, {webinar_title} is one week away. We’ll send another reminder as the event gets closer.',
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
                    'subject' => 'Your webinar is tomorrow',
                    'body' => 'Hi {first_name}, {webinar_title} is tomorrow at {webinar_start_time}. Use the link below when it is time to join.',
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
                    'subject' => 'Your webinar starts in 30 minutes',
                    'body' => 'Hi {first_name}, {webinar_title} starts in 30 minutes. Use the link below to join.',
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
                    'subject' => 'Your webinar is live',
                    'body' => 'Hi {first_name}, {webinar_title} is live now. Use the link below to join.',
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
                    'body' => 'Hi {first_name}, thanks for joining {webinar_title}. You can watch the replay using the link below.',
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
                    'subject' => 'Sorry we missed you — here’s the replay',
                    'body' => 'Hi {first_name}, sorry we missed you at {webinar_title}. You can still watch the replay using the link below.',
                    'cta' => [
                        'label' => 'Watch Replay',
                        'url' => '{webinar_playback_url}',
                    ],
                ],
            ],
        ],
    ],

];