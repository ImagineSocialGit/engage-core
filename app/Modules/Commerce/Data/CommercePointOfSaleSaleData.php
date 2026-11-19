<?php

namespace App\Modules\Commerce\Data;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * A provider-neutral, authoritative POS sale snapshot. The integration owns
 * webhook authentication and must re-read payment/order state before creating
 * this value; a webhook notification alone is not proof of payment.
 */
final readonly class CommercePointOfSaleSaleData
{
    /** @param array<int, CommercePointOfSaleLineData> $lines */
    public function __construct(
        public string $providerKey,
        public string $externalSaleId,
        public string $saleStatus,
        public string $financialStatus,
        public ?DateTimeInterface $paidAt,
        public string $inventoryScope,
        public array $lines,
        public ?string $externalLocationId = null,
        public ?string $providerEventId = null,
    ) {
        foreach ([
            'provider key' => [$this->providerKey, 120],
            'sale identity' => [$this->externalSaleId, 255],
            'sale status' => [$this->saleStatus, 80],
            'financial status' => [$this->financialStatus, 80],
            'inventory scope' => [$this->inventoryScope, 120],
        ] as $field => [$value, $limit]) {
            if (trim($value) === '' || mb_strlen(trim($value)) > $limit) {
                throw new InvalidArgumentException(
                    "Commerce POS {$field} must be nonblank and within {$limit} characters.",
                );
            }
        }

        foreach ([
            'external location identity' => $this->externalLocationId,
            'provider event identity' => $this->providerEventId,
        ] as $field => $value) {
            if ($value !== null && (trim($value) === '' || mb_strlen(trim($value)) > 255)) {
                throw new InvalidArgumentException(
                    "Commerce POS {$field} must be nonblank and at most 255 characters when supplied.",
                );
            }
        }

        $seen = [];

        foreach ($this->lines as $line) {
            if (! $line instanceof CommercePointOfSaleLineData) {
                throw new InvalidArgumentException(
                    'Commerce POS sales require CommercePointOfSaleLineData merchandise lines.',
                );
            }

            $lineId = trim($line->externalLineId);

            if (isset($seen[$lineId])) {
                throw new InvalidArgumentException(
                    'Commerce POS sale line identities must be unique within a sale.',
                );
            }

            $seen[$lineId] = true;
        }
    }
}