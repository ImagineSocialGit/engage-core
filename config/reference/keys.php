
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Engage Core Config Key Registry
    |--------------------------------------------------------------------------
    |
    | This file documents stable keys used when creating default configs,
    | client configs, preset files, message definitions, campaign definitions,
    | FlowRoute definitions, and post-event behavior.
    |
    | Use this registry before adding a new key:
    | 1. If an existing key matches the behavior, use it.
    | 2. If behavior is meaningfully distinct, add a new key here.
    | 3. Client-owned keys may live in client/{client-key}/config/reference/keys.php.
    |
    | Keys are identifiers for behavior/configuration. They are not message tokens.
    */

    'channels' => [
        'email' => [
            'description' => 'Email delivery through Messaging.',
            'status' => 'active',
        ],
        'sms' => [
            'description' => 'SMS delivery through Messaging.',
            'status' => 'active',
        ],
    ],

    'purposes' => [
        'transactional' => [
            'description' => 'Required or expected lifecycle communication tied to a user/requested action.',
            'examples' => [
                'webinar confirmation',
                'webinar reminders',
                'webinar replay follow-up',
            ],
            'status' => 'active',
        ],
        'marketing' => [
            'description' => 'Promotional, nurture, conversion, re-engagement, or educational messaging.',
            'examples' => [
                'webinar nurture',
                'long-term homebuyer nurture',
                'waitlist availability updates',
            ],
            'status' => 'active',
        ],
        'internal' => [
            'description' => 'Team-facing notification messages.',
            'examples' => [
                'inbound reply notification',
                'task digest',
            ],
            'status' => 'active',
        ],
    ],

    'scopes' => [
        'webinar' => [
            'description' => 'Webinar-owned transactional messaging such as confirmations, reminders, live reminders, cancellations, and replay follow-ups.',
            'preferred_purposes' => ['transactional'],
            'status' => 'active',
        ],
        'webinar_nurture' => [
            'description' => 'Marketing nurture after webinar behavior such as attended/missed campaign journeys.',
            'preferred_purposes' => ['marketing'],
            'status' => 'active',
        ],
        'webinar_waitlist' => [
            'description' => 'Marketing/waitlist messages for contacts waiting for future webinar availability.',
            'preferred_purposes' => ['marketing'],
            'status' => 'active',
        ],
        'inbound_messages' => [
            'description' => 'Internal notifications about inbound messages/replies.',
            'preferred_purposes' => ['internal'],
            'status' => 'active',
        ],
        'tasks' => [
            'description' => 'Internal task notification and digest behavior.',
            'preferred_purposes' => ['internal'],
            'status' => 'planned',
        ],
        'broadcast' => [
            'description' => 'Normal one-time Broadcast messaging and imported-contact permission invitation consent scope.',
            'preferred_purposes' => ['marketing'],
            'status' => 'active',
        ],
        'campaign' => [
            'description' => 'Marketing consent scope used by Campaign journeys.',
            'preferred_purposes' => ['marketing'],
            'status' => 'active',
        ],
        'permission_invitation' => [
            'description' => 'Transactional imported-contact permission invitation email flow.',
            'preferred_purposes' => ['transactional'],
            'status' => 'active',
        ],
        'mortgage_homebuyer_nurture' => [
            'description' => 'Mortgage-specific long-term homebuyer nurture messages.',
            'preferred_purposes' => ['marketing'],
            'status' => 'active',
        ],
    ],

    'queues' => [
        'default' => [
            'description' => 'Default application queue.',
            'status' => 'active',
        ],
        'confirmation_messages' => [
            'description' => 'Registration confirmations and similar confirmation messages.',
            'status' => 'active',
        ],
        'opt_in_messages' => [
            'description' => 'Consent opt-in confirmation messages.',
            'status' => 'active',
        ],
        'reminders' => [
            'description' => 'Scheduled reminder messages.',
            'status' => 'active',
        ],
        'post_event' => [
            'description' => 'Post-webinar/provider event processing and follow-up messages.',
            'status' => 'active',
        ],
        'marketing' => [
            'description' => 'Marketing campaign/nurture message scheduling.',
            'status' => 'active',
        ],
        'waitlist' => [
            'description' => 'Waitlist-related notices.',
            'status' => 'active',
        ],
        'notifications' => [
            'description' => 'Internal/team notifications and shared notification work.',
            'status' => 'active',
        ],
        'sms' => [
            'description' => 'Default SMS transport queue when provider-specific scheduling uses it.',
            'status' => 'active',
        ],
        'webinars' => [
            'description' => 'Webinar registration and provider orchestration work.',
            'status' => 'active',
        ],
        'webhooks' => [
            'description' => 'Inbound provider webhook processing.',
            'status' => 'active',
        ],
    ],

    'dispatch_keys' => [
        'registration_created' => [
            'description' => 'A webinar registration was created and registration-related messages should be planned.',
            'used_by' => ['webinars', 'messaging'],
            'recommended_for' => [
                'webinar registration confirmation',
                'webinar reminders',
                'live webinar join reminder',
            ],
            'typical_channel_purpose_scope' => ['email:transactional:webinar', 'sms:transactional:webinar'],
            'status' => 'active',
        ],
        'consent_granted' => [
            'description' => 'Message consent was newly granted.',
            'used_by' => ['messaging'],
            'recommended_for' => [
                'opt-in confirmation messages',
            ],
            'status' => 'active',
        ],
        'webinar_added' => [
            'description' => 'A new webinar became available for waitlisted contacts.',
            'used_by' => ['webinars', 'messaging'],
            'recommended_for' => [
                'webinar waitlist availability notification',
            ],
            'typical_channel_purpose_scope' => ['email:marketing:webinar_waitlist', 'sms:marketing:webinar_waitlist'],
            'status' => 'active',
        ],
        'webinar_ended' => [
            'description' => 'A webinar ended or recording became available and transactional post-webinar follow-up should be planned.',
            'used_by' => ['webinars', 'messaging'],
            'recommended_for' => [
                'attended replay follow-up',
                'missed replay follow-up',
            ],
            'typical_channel_purpose_scope' => ['email:transactional:webinar', 'sms:transactional:webinar'],
            'status' => 'active',
        ],
        'campaign_step_due' => [
            'description' => 'A campaign step is due and should schedule/send its message template.',
            'used_by' => ['campaigns', 'messaging'],
            'recommended_for' => [
                'campaign nurture messages',
                'long-term drip messages',
            ],
            'typical_channel_purpose_scope' => ['email:marketing:webinar_nurture', 'sms:marketing:webinar_nurture'],
            'status' => 'active',
        ],
        'broadcast_send' => [
            'description' => 'A one-time Broadcast message should be scheduled through Messaging.',
            'used_by' => ['broadcasts', 'messaging'],
            'recommended_for' => [
                'one-time email Broadcasts',
                'one-time SMS Broadcasts',
            ],
            'typical_channel_purpose_scope' => ['email:marketing:broadcast', 'sms:marketing:broadcast'],
            'status' => 'active',
        ],
        'imported_contact_permission_invitation' => [
            'description' => 'One-time imported-contact permission invitation email should be planned.',
            'used_by' => ['messaging'],
            'recommended_for' => [
                'imported-contact opt-in invitation',
            ],
            'typical_channel_purpose_scope' => ['email:transactional:permission_invitation'],
            'status' => 'active',
        ],
    ],

    'message_types' => [
        'confirmation' => [
            'description' => 'Reusable confirmation message identity. Exact schedule-slot identity belongs to the owning schedule/profile item or source config path.',
            'dispatch_key' => 'registration_created',
            'status' => 'active',
        ],
        'opt_in' => [
            'description' => 'Consent opt-in confirmation message identity.',
            'dispatch_key' => 'consent_granted',
            'status' => 'active',
        ],
        'reminder' => [
            'description' => 'Reusable reminder message identity. Do not create schedule-specific message types for 10-day, 30-minute, live-now, or similar slots.',
            'dispatch_key' => 'registration_created',
            'status' => 'active',
        ],
        'alert' => [
            'description' => 'Reusable availability/alert message identity, including webinar waitlist availability notices.',
            'dispatch_key' => 'webinar_added',
            'status' => 'active',
        ],
        'post_attended' => [
            'description' => 'Post-webinar transactional follow-up for attended registrations.',
            'dispatch_key' => 'webinar_ended',
            'status' => 'active',
        ],
        'post_missed' => [
            'description' => 'Post-webinar transactional follow-up for missed registrations.',
            'dispatch_key' => 'webinar_ended',
            'status' => 'active',
        ],
        'imported_contact_permission_invitation' => [
            'description' => 'One-time imported-contact permission invitation email.',
            'dispatch_key' => 'imported_contact_permission_invitation',
            'status' => 'active',
        ],
    ],

    'automation_event_keys' => [
        'webinar.registered' => [
            'producer' => 'webinars',
            'contact_required' => true,
            'description' => 'A contact registered for a webinar.',
            'status' => 'active',
        ],
        'webinar.cancelled' => [
            'producer' => 'webinars',
            'contact_required' => true,
            'description' => 'A contact cancelled a webinar registration.',
            'status' => 'active',
        ],
        'webinar.attended' => [
            'producer' => 'webinars',
            'contact_required' => true,
            'description' => 'A contact attended a webinar.',
            'status' => 'active',
        ],
        'webinar.missed' => [
            'producer' => 'webinars',
            'contact_required' => true,
            'description' => 'A registered contact missed a webinar.',
            'status' => 'active',
        ],
        'webinar.ended' => [
            'producer' => 'webinars',
            'contact_required' => false,
            'description' => 'A webinar ended. May be contactless.',
            'status' => 'active',
        ],
        'task.completed' => [
            'producer' => 'tasks',
            'contact_required' => false,
            'description' => 'A task was completed.',
            'status' => 'active',
        ],
        'permission_invitation.accepted' => [
            'producer' => 'messaging',
            'contact_required' => true,
            'description' => 'Reserved for the Phase 7 decision about whether accepted imported-contact permission invitations emit a neutral automation event.',
            'status' => 'planned',
        ],
    ],

    'campaign_keys' => [
        'webinar_attended_nurture' => [
            'description' => 'Default nurture campaign for contacts who attended a webinar.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
        ],
        'webinar_missed_nurture' => [
            'description' => 'Default nurture campaign for contacts who missed a webinar.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
        ],
        'mortgage_homebuyer_nurture' => [
            'description' => 'Mortgage-specific long-term homebuyer education/re-engagement nurture.',
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'status' => 'active',
        ],
    ],

    'flow_route_keys' => [
        'webinar_attended_status_transition' => [
            'description' => 'Route from webinar.attended to the attended-webinar contact status.',
            'trigger' => 'webinar.attended',
            'status' => 'recommended',
        ],
        'webinar_missed_status_transition' => [
            'description' => 'Route from webinar.missed to the missed-webinar contact status.',
            'trigger' => 'webinar.missed',
            'status' => 'recommended',
        ],
        'webinar_attended_campaign_enrollment' => [
            'description' => 'Route from webinar.attended to attended nurture campaign enrollment.',
            'trigger' => 'webinar.attended',
            'status' => 'recommended',
        ],
        'webinar_missed_campaign_enrollment' => [
            'description' => 'Route from webinar.missed to missed nurture campaign enrollment.',
            'trigger' => 'webinar.missed',
            'status' => 'recommended',
        ],
    ],

    'point_types' => [
        'noop',
        'wait',
        'condition',
        'branch_evaluate',
        'event_wait',
        'create_task',
        'send_message',
        'change_status',
        'enroll_campaign',
        'cancel_campaign',
    ],

    'task_template_keys' => [
        'general.follow_up' => [
            'description' => 'General follow-up task.',
            'status' => 'active',
        ],
        'general.review' => [
            'description' => 'Review a contact, request, file, or manual item.',
            'status' => 'active',
        ],
        'general.waiting_on_contact' => [
            'description' => 'Track something the contact needs to provide or complete.',
            'status' => 'active',
        ],
        'general.waiting_on_third_party' => [
            'description' => 'Track a dependency owned by a vendor, partner, or other third party.',
            'status' => 'active',
        ],
        'task_workspace.follow_up' => [
            'description' => 'Simple task-workspace follow-up.',
            'status' => 'active',
        ],
        'task_workspace.review_item' => [
            'description' => 'Review a manual item or dependency.',
            'status' => 'active',
        ],
        'task_workspace.waiting_on_someone' => [
            'description' => 'Track something that depends on another person.',
            'status' => 'active',
        ],
        'webinar.call_high_intent_contact' => [
            'description' => 'Call a high-intent webinar contact.',
            'status' => 'active',
        ],
        'webinar.review_reply' => [
            'description' => 'Review and respond to a webinar-related inbound reply.',
            'status' => 'active',
        ],
        'mortgage.call_contact' => [
            'description' => 'Call the mortgage contact for next-step follow-up.',
            'status' => 'active',
        ],
        'mortgage.contact_documents' => [
            'description' => 'Track documents or information needed from the mortgage contact.',
            'status' => 'active',
        ],
        'mortgage.review_application' => [
            'description' => 'Review mortgage application details.',
            'status' => 'active',
        ],
        'mortgage.waiting_on_realtor' => [
            'description' => 'Track a realtor-owned dependency.',
            'status' => 'active',
        ],
        'mortgage.waiting_on_vendor' => [
            'description' => 'Track a title, appraisal, inspection, or other vendor dependency.',
            'status' => 'active',
        ],
    ],

    'future_keys' => [
        'dispatch_keys' => [
            'appointment_scheduled' => 'A contact appointment/consultation was scheduled.',
            'appointment_cancelled' => 'A contact appointment/consultation was cancelled.',
            'application_started' => 'A contact started an application.',
            'application_submitted' => 'A contact submitted an application.',
            'document_requested' => 'A document was requested from a contact or third party.',
            'document_received' => 'A document was received.',
            'task_created' => 'A task was created and a notification may be needed.',
            'task_due' => 'A task is due soon.',
            'task_overdue' => 'A task is overdue.',
            'task_completed' => 'A task was completed and follow-up may be needed.',
        ],
        'automation_event_keys' => [
            'appointment.scheduled',
            'appointment.cancelled',
            'application.started',
            'application.submitted',
            'document.requested',
            'document.received',
            'inbound_message.received',
        ],
        'scopes' => [
            'appointments',
            'applications',
            'documents',
            'mortgage_nurture',
        ],
    ],

    'client_extension' => [
        'path_pattern' => 'client/{client-key}/config/reference/keys.php',
        'rules' => [
            'Client keys may add dispatch_keys, message_types, campaign_keys, flow_route_keys, task_template_keys, and token contexts.',
            'Client-specific keys should use clear namespacing when they are not reusable across clients.',
            'Do not redefine a core key to mean different behavior.',
            'If a client key becomes generally useful, promote it into this core registry later.',
        ],
        'example_prefixes' => [
            'slam_dunk.va_buyer_follow_up',
            'slam_dunk.credit_review_requested',
            'rob_mortgage_coach.bridge_loan_follow_up',
        ],
    ],

];
