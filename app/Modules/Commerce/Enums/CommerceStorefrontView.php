<?php

namespace App\Modules\Commerce\Enums;

enum CommerceStorefrontView: string
{
    case Home = 'home';
    case Offer = 'offer';
    case Cart = 'cart';
    case CheckoutReturn = 'checkout_return';

    public function defaultView(): string
    {
        return match ($this) {
            self::Home => 'commerce.storefront.index',
            self::Offer => 'commerce.storefront.offer',
            self::Cart => 'commerce.storefront.cart',
            self::CheckoutReturn => 'commerce.storefront.checkout-return',
        };
    }
}