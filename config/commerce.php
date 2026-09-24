<?php

use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Enums\CommerceStorefrontView;

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

    /*
    |--------------------------------------------------------------------------
    | Storefront Presentation
    |--------------------------------------------------------------------------
    |
    | Commerce will ship a complete default Blade storefront. Client repos may
    | override any logical storefront view independently without replacing
    | Commerce controllers, view models, provider resolution, or checkout
    | orchestration. The default public views themselves land with the public
    | storefront surface; these names establish the presentation seam now.
    |
    */

    'storefront' => [
        'views' => [
            CommerceStorefrontView::Home->value => CommerceStorefrontView::Home->defaultView(),
            CommerceStorefrontView::Offer->value => CommerceStorefrontView::Offer->defaultView(),
            CommerceStorefrontView::Cart->value => CommerceStorefrontView::Cart->defaultView(),
            CommerceStorefrontView::CheckoutReturn->value => CommerceStorefrontView::CheckoutReturn->defaultView(),
        ],
    ],
];