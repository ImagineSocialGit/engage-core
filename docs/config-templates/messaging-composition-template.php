<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Messaging Template Composition
    |--------------------------------------------------------------------------
    |
    | Composition is bounded authoring state. It is resolved during template
    | publication into a complete immutable MessageTemplateVersion. Runtime
    | delivery never walks these layers.
    |
    | Supported scope_type values:
    |   platform        no client/context/family selector
    |   client          selected client only
    |   family          selected client + family_key
    |   context         selected client + context_key
    |   context_family  selected client + context_key + family_key
    |
    | message scope is intentionally not config-authored. Specific-message
    | overrides are DB-owned editor state attached to MessageTemplate identity.
    |
    | Client config must not repeat client_key; the selected client is the
    | authoritative client selector.
    |
    | Email payload fields currently supported:
    |   subject, body, footer, cta, ctas, secondary_link
    |
    | SMS payload field currently supported:
    |   message
    |
    | Top-level fields are atomic. Arrays such as ctas replace as a complete
    | unit rather than recursively merging. A null value explicitly removes an
    | inherited field.
    |
    */
    'layers' => [
        'example_email_family_cta' => [
            'scope_type' => 'family',
            'channel' => 'email',
            'family_key' => 'reminder',
            'source_version' => 1,
            'payload' => [
                'cta' => [
                    'label' => 'Join',
                    'url' => '{documented_runtime_url_token}',
                ],
            ],
        ],
    ],
];