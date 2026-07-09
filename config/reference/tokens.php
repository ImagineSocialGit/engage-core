
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Engage Core Token Reference
    |--------------------------------------------------------------------------
    |
    | This file documents which tokens are safe to use when authoring Messaging
    | payloads, Campaign message steps, FlowRoute send_message points, and
    | client-specific message configs.
    |
    | Principle:
    | - Model-backed tokens should come from intentional non-meta fields.
    | - Meta/raw/provider payloads are not public message-token contracts unless
    |   explicitly exposed here.
    | - Friendly aliases are allowed for copywriting readability and formatting.
    | - Client configs may extend this reference in client/{client-key}/config/reference/tokens.php.
    |
    | Runtime note:
    | EmailPayload and SmsPayload resolve tokens from runtime_context, context,
    | and tokens arrays. That means token replacement is dynamic, but configs
    | should only use tokens documented for the current message context.
    */

    'syntax' => [
        'braces' => '{token_name}',
        'dot_notation' => '{contact.first_name}',
        'colon_alias' => ':token_name',
        'notes' => [
            'Dot notation is generated from nested runtime_context/context/tokens arrays.',
            'Use brace syntax in client-facing config copy.',
            'Colon aliases are supported by payload classes but should not be preferred in authored configs.',
        ],
    ],

    'policy' => [
        'default_source' => 'non_meta_model_fields',
        'exclude_by_default' => [
            'meta',
            'raw',
            'provider raw payloads',
            'secret tokens',
            'provider access tokens',
            'internal morph internals unless documented for FlowRoutes',
        ],
        'formatting_rule' => 'Prefer friendly aliases for dates, times, links, and human-facing copy.',
    ],


    /*
    |--------------------------------------------------------------------------
    | Contact authoring aliases
    |--------------------------------------------------------------------------
    |
    | Authoring UI may derive client-facing aliases from the configured Contact
    | noun without changing runtime identity. For example, a configured noun of
    | "fan" may expose fan_first_name while runtime validation resolves it to
    | contact.first_name.
    |
    */

    'contact_authoring_aliases' => [
        'label_config_path' => 'contacts.labels.singular',
        'fields' => [
            'first_name' => 'contact.first_name',
            'last_name' => 'contact.last_name',
            'name' => 'contact.name',
            'email' => 'contact.email',
            'phone' => 'contact.phone',
        ],
        'rules' => [
            'Alias names are presentation/authoring conveniences only.',
            'Normalize recognized aliases to canonical Contact fields before availability validation.',
            'Do not create separate runtime payload fields, schema columns, event keys, preset keys, route keys, or validation branches for each configured noun.',
        ],
    ],

    'models' => [

        'contact' => [
            'owner' => 'core',
            'model' => App\Modules\Core\Models\Contact::class,
            'available_fields' => [
                'id',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'source',
                'subsource',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{contact.id}',
                '{contact.first_name}',
                '{contact.last_name}',
                '{contact.name}',
                '{contact.email}',
                '{contact.phone}',
                '{contact.source}',
                '{contact.subsource}',
                '{contact.created_at}',
                '{contact.updated_at}',
            ],
            'aliases' => [
                'first_name' => 'contact.first_name',
                'last_name' => 'contact.last_name',
                'name' => 'contact.name',
                'email' => 'contact.email',
                'phone' => 'contact.phone',
            ],
        ],

        'webinar' => [
            'owner' => 'webinars',
            'model' => App\Modules\Webinars\Models\Webinar::class,
            'available_fields' => [
                'id',
                'webinar_series_id',
                'title',
                'slug',
                'status',
                'join_url',
                'registration_url',
                'platform',
                'external_id',
                'host_account_key',
                'starts_at',
                'timezone',
                'ends_at',
                'description',
                'playback_token',
                'playback_url',
                'playback_passcode',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{webinar.id}',
                '{webinar.webinar_series_id}',
                '{webinar.title}',
                '{webinar.slug}',
                '{webinar.status}',
                '{webinar.join_url}',
                '{webinar.registration_url}',
                '{webinar.platform}',
                '{webinar.external_id}',
                '{webinar.host_account_key}',
                '{webinar.starts_at}',
                '{webinar.timezone}',
                '{webinar.ends_at}',
                '{webinar.description}',
                '{webinar.playback_url}',
                '{webinar.playback_passcode}',
            ],
            'aliases' => [
                'webinar_title' => 'webinar.title',
                'webinar_slug' => 'webinar.slug',
                'webinar_timezone' => 'webinar.timezone',
                'webinar_join_url' => 'webinar.join_url',
                'webinar_registration_url' => 'webinar.registration_url',
                'webinar_playback_url' => 'webinar.playback_url',
                'webinar_start_date' => 'formatted webinar.starts_at date',
                'webinar_start_time' => 'formatted webinar.starts_at time',
            ],
            'deprecated_or_avoid' => [
                'webinar_starts_at' => 'Prefer webinar_start_date and webinar_start_time for message copy.',
                'webinar_replay_url' => 'Prefer webinar_playback_url.',
                'playback_url' => 'Prefer webinar_playback_url unless kept as an explicit alias.',
            ],
        ],

        'webinar_series' => [
            'owner' => 'webinars',
            'model' => App\Modules\Webinars\Models\WebinarSeries::class,
            'available_fields' => [
                'id',
                'title',
                'slug',
                'status',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{webinar_series.id}',
                '{webinar_series.title}',
                '{webinar_series.slug}',
                '{webinar_series.status}',
            ],
            'aliases' => [
                'webinar_series' => 'webinar_series.title',
                'webinar_series_title' => 'webinar_series.title',
                'webinar_series_slug' => 'webinar_series.slug',
            ],
        ],

        'webinar_registration' => [
            'owner' => 'webinars',
            'model' => App\Modules\Webinars\Models\WebinarRegistration::class,
            'available_fields' => [
                'id',
                'contact_id',
                'webinar_id',
                'webinar_slug',
                'status',
                'source',
                'registered_at',
                'attended_at',
                'cancelled_at',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{webinar_registration.id}',
                '{webinar_registration.contact_id}',
                '{webinar_registration.webinar_id}',
                '{webinar_registration.webinar_slug}',
                '{webinar_registration.status}',
                '{webinar_registration.source}',
                '{webinar_registration.registered_at}',
                '{webinar_registration.attended_at}',
                '{webinar_registration.cancelled_at}',
            ],
            'aliases' => [
                'registration_attended_at' => 'webinar_registration.attended_at',
                'cancel_registration_url' => 'generated cancellation URL for the registration',
            ],
        ],

        'webinar_waitlist_signup' => [
            'owner' => 'webinars',
            'model' => App\Modules\Webinars\Models\WebinarWaitlistSignup::class,
            'available_fields' => [
                'id',
                'contact_id',
                'webinar_series_id',
                'source',
                'notified_at',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{webinar_waitlist_signup.id}',
                '{webinar_waitlist_signup.contact_id}',
                '{webinar_waitlist_signup.webinar_series_id}',
                '{webinar_waitlist_signup.source}',
                '{webinar_waitlist_signup.notified_at}',
            ],
        ],

        'campaign' => [
            'owner' => 'campaigns',
            'model' => App\Modules\Campaigns\Models\Campaign::class,
            'available_fields' => [
                'id',
                'key',
                'name',
                'description',
                'channel',
                'purpose',
                'scope',
                'status',
                'is_active',
                'source_version',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{campaign.id}',
                '{campaign.key}',
                '{campaign.name}',
                '{campaign.description}',
                '{campaign.channel}',
                '{campaign.purpose}',
                '{campaign.scope}',
                '{campaign.status}',
            ],
        ],

        'campaign_enrollment' => [
            'owner' => 'campaigns',
            'model' => App\Modules\Campaigns\Models\CampaignEnrollment::class,
            'available_fields' => [
                'id',
                'contact_id',
                'campaign_id',
                'source_type',
                'source_id',
                'campaign_key',
                'channel',
                'purpose',
                'scope',
                'status',
                'current_step',
                'current_campaign_step_id',
                'last_scheduled_message_id',
                'started_at',
                'completed_at',
                'exited_at',
                'exit_reason',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{campaign_enrollment.id}',
                '{campaign_enrollment.campaign_key}',
                '{campaign_enrollment.channel}',
                '{campaign_enrollment.purpose}',
                '{campaign_enrollment.scope}',
                '{campaign_enrollment.status}',
                '{campaign_enrollment.current_step}',
            ],
            'notes' => 'Additional campaign-specific copy tokens should be supplied through payload/start_context and documented per campaign.',
        ],

        'task' => [
            'owner' => 'tasks',
            'model' => App\Modules\Tasks\Models\Task::class,
            'available_fields' => [
                'id',
                'related_type',
                'related_id',
                'assigned_to_type',
                'assigned_to_id',
                'responsible_party',
                'responsible_type',
                'responsible_id',
                'source',
                'title',
                'description',
                'status',
                'priority',
                'due_at',
                'completed_at',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{task.id}',
                '{task.title}',
                '{task.description}',
                '{task.status}',
                '{task.priority}',
                '{task.responsible_party}',
                '{task.due_at}',
                '{task.completed_at}',
            ],
        ],

        'team_member' => [
            'owner' => 'internal_notifications',
            'model' => App\Modules\InternalNotifications\Models\TeamMember::class,
            'available_fields' => [
                'id',
                'user_id',
                'name',
                'email',
                'phone',
                'role',
                'is_active',
                'created_at',
                'updated_at',
            ],
            'tokens' => [
                '{team_member.id}',
                '{team_member.name}',
                '{team_member.email}',
                '{team_member.phone}',
                '{team_member.role}',
            ],
        ],
    ],

    'contexts' => [

        'registration_created' => [
            'description' => 'Webinar registration confirmations and reminders.',
            'dispatch_key' => 'registration_created',
            'default_channel_purpose_scope' => 'email:transactional:webinar',
        
    /*
    |--------------------------------------------------------------------------
    | Contact authoring aliases
    |--------------------------------------------------------------------------
    |
    | Authoring UI may derive client-facing aliases from the configured Contact
    | noun without changing runtime identity. For example, a configured noun of
    | "fan" may expose fan_first_name while runtime validation resolves it to
    | contact.first_name.
    |
    */

    'contact_authoring_aliases' => [
        'label_config_path' => 'contacts.labels.singular',
        'fields' => [
            'first_name' => 'contact.first_name',
            'last_name' => 'contact.last_name',
            'name' => 'contact.name',
            'email' => 'contact.email',
            'phone' => 'contact.phone',
        ],
        'rules' => [
            'Alias names are presentation/authoring conveniences only.',
            'Normalize recognized aliases to canonical Contact fields before availability validation.',
            'Do not create separate runtime payload fields, schema columns, event keys, preset keys, route keys, or validation branches for each configured noun.',
        ],
    ],

    'models' => [
                'contact',
                'webinar',
                'webinar_registration',
                'webinar_series',
            ],
            'approved_aliases' => [
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'webinar_title',
                'webinar_slug',
                'webinar_start_date',
                'webinar_start_time',
                'webinar_timezone',
                'webinar_join_url',
                'cancel_registration_url',
                'cta',
            ],
        ],

        'consent_granted' => [
            'description' => 'Opt-in messages after active consent is newly granted.',
            'dispatch_key' => 'consent_granted',
        
    /*
    |--------------------------------------------------------------------------
    | Contact authoring aliases
    |--------------------------------------------------------------------------
    |
    | Authoring UI may derive client-facing aliases from the configured Contact
    | noun without changing runtime identity. For example, a configured noun of
    | "fan" may expose fan_first_name while runtime validation resolves it to
    | contact.first_name.
    |
    */

    'contact_authoring_aliases' => [
        'label_config_path' => 'contacts.labels.singular',
        'fields' => [
            'first_name' => 'contact.first_name',
            'last_name' => 'contact.last_name',
            'name' => 'contact.name',
            'email' => 'contact.email',
            'phone' => 'contact.phone',
        ],
        'rules' => [
            'Alias names are presentation/authoring conveniences only.',
            'Normalize recognized aliases to canonical Contact fields before availability validation.',
            'Do not create separate runtime payload fields, schema columns, event keys, preset keys, route keys, or validation branches for each configured noun.',
        ],
    ],

    'models' => [
                'contact',
            ],
            'approved_aliases' => [
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
            ],
        ],

        'webinar_added' => [
            'description' => 'Waitlist notification when a new webinar is available.',
            'dispatch_key' => 'webinar_added',
            'default_channel_purpose_scope' => 'email:marketing:webinar_waitlist',
        
    /*
    |--------------------------------------------------------------------------
    | Contact authoring aliases
    |--------------------------------------------------------------------------
    |
    | Authoring UI may derive client-facing aliases from the configured Contact
    | noun without changing runtime identity. For example, a configured noun of
    | "fan" may expose fan_first_name while runtime validation resolves it to
    | contact.first_name.
    |
    */

    'contact_authoring_aliases' => [
        'label_config_path' => 'contacts.labels.singular',
        'fields' => [
            'first_name' => 'contact.first_name',
            'last_name' => 'contact.last_name',
            'name' => 'contact.name',
            'email' => 'contact.email',
            'phone' => 'contact.phone',
        ],
        'rules' => [
            'Alias names are presentation/authoring conveniences only.',
            'Normalize recognized aliases to canonical Contact fields before availability validation.',
            'Do not create separate runtime payload fields, schema columns, event keys, preset keys, route keys, or validation branches for each configured noun.',
        ],
    ],

    'models' => [
                'contact',
                'webinar',
                'webinar_series',
                'webinar_waitlist_signup',
            ],
            'approved_aliases' => [
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'webinar_title',
                'webinar_slug',
                'webinar_start_date',
                'webinar_start_time',
                'webinar_timezone',
                'webinar_join_url',
                'webinar_registration_url',
                'webinar_series',
                'webinar_series_title',
            ],
        ],

        'webinar_ended' => [
            'description' => 'Post-webinar transactional replay/follow-up messages.',
            'dispatch_key' => 'webinar_ended',
            'default_channel_purpose_scope' => 'email:transactional:webinar',
        
    /*
    |--------------------------------------------------------------------------
    | Contact authoring aliases
    |--------------------------------------------------------------------------
    |
    | Authoring UI may derive client-facing aliases from the configured Contact
    | noun without changing runtime identity. For example, a configured noun of
    | "fan" may expose fan_first_name while runtime validation resolves it to
    | contact.first_name.
    |
    */

    'contact_authoring_aliases' => [
        'label_config_path' => 'contacts.labels.singular',
        'fields' => [
            'first_name' => 'contact.first_name',
            'last_name' => 'contact.last_name',
            'name' => 'contact.name',
            'email' => 'contact.email',
            'phone' => 'contact.phone',
        ],
        'rules' => [
            'Alias names are presentation/authoring conveniences only.',
            'Normalize recognized aliases to canonical Contact fields before availability validation.',
            'Do not create separate runtime payload fields, schema columns, event keys, preset keys, route keys, or validation branches for each configured noun.',
        ],
    ],

    'models' => [
                'contact',
                'webinar',
                'webinar_registration',
                'webinar_series',
            ],
            'approved_aliases' => [
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'webinar_title',
                'webinar_slug',
                'webinar_start_date',
                'webinar_start_time',
                'webinar_timezone',
                'webinar_playback_url',
                'registration_attended_at',
                'cta',
            ],
            'deprecated_or_avoid' => [
                'playback_url' => 'Prefer webinar_playback_url.',
                'webinar_replay_url' => 'Prefer webinar_playback_url.',
            ],
        ],

        'campaign_step_due' => [
            'description' => 'Campaign nurture message step scheduling/sending.',
            'dispatch_key' => 'campaign_step_due',
            'default_channel_purpose_scope' => 'email:marketing:webinar_nurture',
        
    /*
    |--------------------------------------------------------------------------
    | Contact authoring aliases
    |--------------------------------------------------------------------------
    |
    | Authoring UI may derive client-facing aliases from the configured Contact
    | noun without changing runtime identity. For example, a configured noun of
    | "fan" may expose fan_first_name while runtime validation resolves it to
    | contact.first_name.
    |
    */

    'contact_authoring_aliases' => [
        'label_config_path' => 'contacts.labels.singular',
        'fields' => [
            'first_name' => 'contact.first_name',
            'last_name' => 'contact.last_name',
            'name' => 'contact.name',
            'email' => 'contact.email',
            'phone' => 'contact.phone',
        ],
        'rules' => [
            'Alias names are presentation/authoring conveniences only.',
            'Normalize recognized aliases to canonical Contact fields before availability validation.',
            'Do not create separate runtime payload fields, schema columns, event keys, preset keys, route keys, or validation branches for each configured noun.',
        ],
    ],

    'models' => [
                'contact',
                'campaign',
                'campaign_enrollment',
            ],
            'approved_aliases' => [
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
            ],
            'caller_supplied_aliases' => [
                'webinar_title',
                'webinar_playback_url',
                'application_url',
                'contact_url',
                'webinar_registration_url',
            ],
            'notes' => 'Campaign-specific tokens must be supplied by the enrolling caller, start_context, or explicit payload. Do not assume webinar tokens exist for every campaign.',
        ],

        'flow_route_send_message' => [
            'description' => 'FlowRoutes send_message point payload interpolation.',
        
    /*
    |--------------------------------------------------------------------------
    | Contact authoring aliases
    |--------------------------------------------------------------------------
    |
    | Authoring UI may derive client-facing aliases from the configured Contact
    | noun without changing runtime identity. For example, a configured noun of
    | "fan" may expose fan_first_name while runtime validation resolves it to
    | contact.first_name.
    |
    */

    'contact_authoring_aliases' => [
        'label_config_path' => 'contacts.labels.singular',
        'fields' => [
            'first_name' => 'contact.first_name',
            'last_name' => 'contact.last_name',
            'name' => 'contact.name',
            'email' => 'contact.email',
            'phone' => 'contact.phone',
        ],
        'rules' => [
            'Alias names are presentation/authoring conveniences only.',
            'Normalize recognized aliases to canonical Contact fields before availability validation.',
            'Do not create separate runtime payload fields, schema columns, event keys, preset keys, route keys, or validation branches for each configured noun.',
        ],
    ],

    'models' => [
                'contact',
            ],
            'flow_route_only_tokens' => [
                'contact.id',
                'contact_status.id',
                'workflow_profile.id',
                'flow_route.id',
                'flow_route_point.id',
                'point.id',
            ],
            'notes' => 'These are route/point interpolation tokens, not general client-facing email copy tokens.',
        ],
    ],

    'deprecated_or_avoid' => [
        'webinar_starts_at' => 'Prefer webinar_start_date and webinar_start_time.',
        'webinar_replay_url' => 'Prefer webinar_playback_url.',
        'playback_url' => 'Prefer webinar_playback_url unless explicitly kept as a compatibility alias.',
        'registration_url' => 'Prefer webinar_registration_url or webinar_join_url, depending on behavior.',
        'application_url' => 'Only use when caller supplies it for the campaign/context.',
        'contact_url' => 'Only use when caller supplies it or CRM URL generation is added.',
    ],

    'client_extension' => [
        'path_pattern' => 'client/{client-key}/config/reference/tokens.php',
        'rule' => 'Client files may add models, aliases, contexts, and caller_supplied_aliases. They should not override core aliases to mean something different.',
    ],

];
