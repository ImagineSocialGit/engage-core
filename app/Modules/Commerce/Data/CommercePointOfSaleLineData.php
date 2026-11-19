<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

/**
 * One stock-tracked merchandise line from a verified POS sale. Provider
 * adapters must exclude payments, tips, fees and other non-inventory lines.
 */
final readonly class CommercePointOfSaleLineData
{
    public function __construct(
        public string $externalLineId,
        public string $referenceType,
        public string $externalVariantId,
        public int $quantity,
    ) {
        foreach ([
            'line identity' => [$this->externalLineId, 255],
            'variant reference type' => [$this->referenceType, 80],
            'external variant identity' => [$this->externalVariantId, 255],
        ] as $field => [$value, $limit]) {
            if (trim($value) === '' || mb_strlen(trim($value)) > $limit) {
                throw new InvalidArgumentException(
                    "Commerce POS {$field} must be nonblank and within {$limit} characters.",
                );
            }
        }

        if ($this->quantity < 1 || $this->quantity > 99999999) {
            throw new InvalidArgumentException(
                'Commerce POS merchandise quantity must be a positive whole number within the inventory precision.',
            );
        }
    }
}