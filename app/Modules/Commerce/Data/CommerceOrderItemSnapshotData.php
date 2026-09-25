<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceOrderItemSnapshotData
{
    /**
     * @param array<string, string> $options
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $externalId,
        public ?string $externalProductId = null,
        public ?string $externalVariantId = null,
        public ?string $sku = null,
        public ?string $name = null,
        public ?string $title = null,
        public ?string $variantTitle = null,
        public array $options = [],
        public string $quantity = '1.0000',
        public ?string $currency = null,
        public int $unitPriceCents = 0,
        public int $discountCents = 0,
        public int $taxCents = 0,
        public int $totalCents = 0,
        public ?string $fulfillmentStatus = null,
        public ?string $externalUrl = null,
        public array $meta = [],
    ) {
        if (trim($this->externalId) === '') {
            throw new InvalidArgumentException(
                'Commerce order item external identity cannot be empty.',
            );
        }

        if (preg_match('/^\d{1,8}(?:\.\d{1,4})?$/D', trim($this->quantity)) !== 1) {
            throw new InvalidArgumentException(
                'Commerce order item quantity must fit a non-negative decimal(12,4) value.',
            );
        }

        foreach ([
            'unit price' => $this->unitPriceCents,
            'discount' => $this->discountCents,
            'tax' => $this->taxCents,
            'total' => $this->totalCents,
        ] as $field => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(
                    "Commerce order item {$field} cents cannot be negative.",
                );
            }
        }

        if ($this->currency !== null
            && preg_match('/^[A-Za-z]{3}$/D', trim($this->currency)) !== 1
        ) {
            throw new InvalidArgumentException(
                'Commerce order item currency must be a three-letter code when supplied.',
            );
        }
    }
}