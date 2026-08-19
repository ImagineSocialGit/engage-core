<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Contact Relationships Template
    |--------------------------------------------------------------------------
    |
    | File paths:
    | config/relationships.php
    | client/{client-key}/config/relationships.php
    |
    | Contact is the universal person identity. Relationship definitions create
    | separate business operating contexts without duplicating the Contact.
    |
    | Relationship keys and stage keys are stable lowercase snake_case runtime
    | identifiers. Client-facing singular/plural labels may use business nouns
    | such as Lead, Customer, Realtor, Collaborator, Partner, or Vendor.
    |
    | Do not use relationship types as tags. A relationship has durable current
    | stage/source state and is a first-class CRM population.
    |
    */

    'types' => [
        'consumer' => [
            'singular' => 'Lead',
            'plural' => 'Leads',
            'visible' => true,
            'sort_order' => 10,
            'stages' => [
                'new' => [
                    'label' => 'New',
                    'sort_order' => 10,
                    'active' => true,
                ],
                'nurture' => [
                    'label' => 'Nurture',
                    'sort_order' => 20,
                    'active' => true,
                ],
            ],
        ],

        'collaborator' => [
            'singular' => 'Collaborator',
            'plural' => 'Collaborators',
            'visible' => true,
            'sort_order' => 20,
            'stages' => [
                'target' => [
                    'label' => 'Target',
                    'sort_order' => 10,
                    'active' => true,
                ],
                'partner' => [
                    'label' => 'Partner',
                    'sort_order' => 20,
                    'active' => true,
                ],
            ],
        ],
    ],

    /*
    | A valid SiteSetting under this key takes precedence. This config value is
    | the deployment/client fallback used when no stored operator preference is
    | present. The selected key must identify a visible relationship type.
    */
    'default_relationship' => 'consumer',

    'default_relationship_setting_key' => 'crm.contacts.default_relationship',
];