<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ephemeral first-party session policy
    |--------------------------------------------------------------------------
    |
    | These are privacy ceilings, not tuning targets. Client config may narrow
    | them but setup validation rejects broader correlation windows.
    |
    */

    'session' => [
        'inactivity_minutes' => 30,
        'absolute_minutes' => 240,
        'token_min_length' => 16,
        'token_max_length' => 255,
    ],


    /*
    |--------------------------------------------------------------------------
    | Public browser collection
    |--------------------------------------------------------------------------
    |
    | Browser collection is passive until a versioned event definition declares
    | one or more exact browser hosts. The transport remains same-origin and the
    | Reporting module must also be enabled at runtime.
    |
    */

    'collection' => [
        'browser_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Observation ingestion limits
    |--------------------------------------------------------------------------
    */

    'ingestion' => [
        'max_payload_bytes' => 8192,
        'max_properties' => 16,
        'max_property_key_length' => 80,
        'max_string_length' => 512,
        'max_string_list_items' => 16,
        'max_classification_reasons' => 8,
        'occurred_at_past_seconds' => 86400,
        'occurred_at_future_seconds' => 300,
        'rate_limit_per_ip_per_minute' => 120,
        'rate_limit_per_session_per_minute' => 90,
        'allowed_sources' => [
            'browser',
            'server',
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Browser request classification
    |--------------------------------------------------------------------------
    |
    | The implementation is code-owned and versioned. Client configuration may
    | disable browser collection but may not redefine the classifier heuristics.
    |
    */

    'classification' => [
        'browser_classifier' => 'request_signals_v3',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attribution allowlist
    |--------------------------------------------------------------------------
    |
    | Query parameters are collected only when explicitly allowlisted; the public
    | browser transport rejects submitted unknown keys. Stable external campaign
    | identity uses the canonical engage_* keys below. Raw external click IDs are
    | never persisted. Leave click_id_keys empty until a concrete approved
    | reconciliation use exists and a dedicated secret hash key is configured.
    |
    */

    'attribution' => [
        'path_max_length' => 512,
        'host_max_length' => 255,
        'value_max_length' => 120,

        'utm_keys' => [
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
            'utm_content' => 'utm_content',
            'utm_term' => 'utm_term',
        ],

        'external_keys' => [
            'platform' => 'engage_platform',
            'campaign_id' => 'engage_campaign_id',
            'group_id' => 'engage_group_id',
            'creative_id' => 'engage_creative_id',
            'placement' => 'engage_placement',
        ],

        'click_id_keys' => [],
        'click_id_hash_key' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention ceilings
    |--------------------------------------------------------------------------
    |
    | Pruning is not scheduled by this foundation. Later projection/retention
    | work must prove required aggregate completion before deleting raw data.
    |
    */

    'retention' => [
        'raw_observations_days' => 45,
        'diagnostics_days' => 90,
        'daily_aggregate_months' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Versioned browser event definitions
    |--------------------------------------------------------------------------
    |
    | Shape:
    |
    | 'event.key' => [
    |     1 => [
    |         'surfaces' => ['example_public_surface'],
    |         'browser_hosts' => ['public.example.test'],
    |         'session_mode' => 'expected',
    |         'funnel_eligible' => true,
    |         'properties' => [
    |             'page_revision' => [
    |                 'type' => 'string',
    |                 'max_length' => 80,
    |             ],
    |         ],
    |     ],
    | ],
    |
    | Reporting-owned definitions may live here. Producer modules may instead
    | contribute definitions through the shared event-definition contributor
    | seam, which keeps Reporting independent from those modules. A definition
    | with no browser_hosts remains unavailable to the public browser endpoint.
    |
    */

    'events' => [],

];