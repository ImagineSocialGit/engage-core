<?php

namespace App\Modules\Billing\Data;

use InvalidArgumentException;

final readonly class BillingCollectionRequest
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $billingPaymentRequestId,
        public int $amountCents,
        public string $currency,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
        public array $meta = [],
    ) {
        if ($this->billingPaymentRequestId < 1) {
            throw new InvalidArgumentException(
                'Billing collection requests require a positive payment request identity.',
            );
        }

        if ($this->amountCents < 1) {
            throw new InvalidArgumentException(
                'Billing collection requests require a positive amount.',
            );
        }

        if (preg_match('/^[A-Z]{3}$/D', trim($this->currency)) !== 1) {
            throw new InvalidArgumentException(
                'Billing collection request currency must be a three-letter uppercase currency code.',
            );
        }

        foreach ([$this->successUrl, $this->cancelUrl] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException(
                    'Billing collection return URLs must be valid absolute URLs.',
                );
            }
        }

        if ($this->customerEmail !== null
            && filter_var($this->customerEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                'Billing collection customer email must be valid when provided.',
            );
        }
    }
}