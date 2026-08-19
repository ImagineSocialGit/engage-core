<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Contact Relationship Definitions
    |--------------------------------------------------------------------------
    |
    | A Core Contact is the canonical person identity. Relationships describe
    | the distinct business contexts in which that person participates, such
    | as consumer/customer, collaborator, Realtor, vendor, or referral partner.
    |
    | Core ships no business-specific relationship types. Vertical/client
    | configuration owns the concrete relationship keys, labels, and stages.
    |
    */

    'types' => [],

    /*
    |--------------------------------------------------------------------------
    | Default Contact Workspace
    |--------------------------------------------------------------------------
    |
    | Normal CRM work should open a single relationship-scoped population, not
    | an undifferentiated list containing unrelated business relationships.
    |
    | A valid SiteSetting stored under default_relationship_setting_key wins
    | over this config fallback. Both values must reference a visible type.
    |
    */

    'default_relationship' => null,

    'default_relationship_setting_key' => 'crm.contacts.default_relationship',
];