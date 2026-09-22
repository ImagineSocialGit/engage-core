<?php

use App\Modules\Commerce\Enums\CommerceProviderRole;

return [
    /*
    |--------------------------------------------------------------------------
    | Provider Role Bindings
    |--------------------------------------------------------------------------
    |
    | Commerce owns provider-neutral roles. Installed integration packages
    | register provider implementations; client configuration selects which
    | registered provider satisfies each role. A role may optionally vary by
    | business scope without changing the canonical Commerce schema.
    |
    */

    'provider_roles' => [
        CommerceProviderRole::Catalog->value => [
            'default' => null,
            'scopes' => [],
        ],
        CommerceProviderRole::Pricing->value => [
            'default' => null,
            'scopes' => [],
        ],
        CommerceProviderRole::Promotion->value => [
            'default' => null,
            'scopes' => [],
        ],
        CommerceProviderRole::Checkout->value => [
            'default' => null,
            'scopes' => [],
        ],
        CommerceProviderRole::Payment->value => [
            'default' => null,
            'scopes' => [],
        ],
        CommerceProviderRole::Orders->value => [
            'default' => null,
            'scopes' => [],
        ],
        CommerceProviderRole::Inventory->value => [
            'default' => null,
            'scopes' => [],
        ],
        CommerceProviderRole::Fulfillment->value => [
            'default' => null,
            'scopes' => [],
        ],
        CommerceProviderRole::PointOfSale->value => [
            'default' => null,
            'scopes' => [],
        ],
    ],
];