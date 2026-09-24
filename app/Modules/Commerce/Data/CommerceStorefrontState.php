<?php

namespace App\Modules\Commerce\Data;

use App\Modules\Commerce\Models\CommerceOffer;
use App\Modules\Commerce\Models\CommerceOfferVariant;

final readonly class CommerceStorefrontState
{
    public function __construct(
        public CommerceOffer $offer,
        public CommerceOfferVariant $offerVariant,
        public CommercePricingState $pricing,
        public ?CommercePromotionState $promotion,
        public string $checkoutProviderKey,
    ) {}

    public function canCheckout(): bool
    {
        return $this->pricing->availableForSale
            && trim($this->checkoutProviderKey) !== '';
    }
}