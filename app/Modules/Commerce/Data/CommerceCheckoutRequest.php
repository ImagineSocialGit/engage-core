<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceCheckoutRequest
{
    public function __construct(
        public int $commerceOfferId,
        public CommerceProviderVariantReference $variant,
        public string $quantity,
        public string $successUrl,
        public string $cancelUrl,
        public ?CommercePromotionState $promotion = null,
    ) {
        if ($this->commerceOfferId < 1) {
            throw new InvalidArgumentException('Commerce checkout offer identity must be a positive integer.');
        }

        if (preg_match('/^\d+(?:\.\d{1,4})?$/D', $this->quantity) !== 1
            || (float) $this->quantity <= 0
        ) {
            throw new InvalidArgumentException('Commerce checkout quantity must be a positive decimal with at most four decimal places.');
        }

        foreach ([$this->successUrl, $this->cancelUrl] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Commerce checkout return URLs must be valid absolute URLs.');
            }
        }
    }
}