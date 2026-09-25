<?php

namespace App\Modules\Commerce\Data;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class CommerceOrderSnapshotData
{
    /**
     * @param array<int, CommerceOrderItemSnapshotData> $items
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $externalId,
        public ?string $orderNumber,
        public ?string $orderName,
        public string $status,
        public ?string $financialStatus,
        public ?string $fulfillmentStatus,
        public ?string $currency,
        public int $subtotalCents,
        public int $discountCents,
        public int $taxCents,
        public int $shippingCents,
        public int $totalCents,
        public ?DateTimeInterface $orderedAt,
        public ?DateTimeInterface $closedAt,
        public ?DateTimeInterface $cancelledAt,
        public ?DateTimeInterface $refundedAt,
        public ?string $externalUrl,
        public array $items,
        public array $meta = [],
    ) {
        if (trim($this->externalId) === '') {
            throw new InvalidArgumentException(
                'Commerce order external identity cannot be empty.',
            );
        }

        if (trim($this->status) === '') {
            throw new InvalidArgumentException(
                'Commerce order status cannot be empty.',
            );
        }

        if ($this->currency !== null
            && preg_match('/^[A-Za-z]{3}$/D', trim($this->currency)) !== 1
        ) {
            throw new InvalidArgumentException(
                'Commerce order currency must be a three-letter code when supplied.',
            );
        }

        foreach ([
            'subtotal' => $this->subtotalCents,
            'discount' => $this->discountCents,
            'tax' => $this->taxCents,
            'shipping' => $this->shippingCents,
            'total' => $this->totalCents,
        ] as $field => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(
                    "Commerce order {$field} cents cannot be negative.",
                );
            }
        }

        foreach ($this->items as $item) {
            if (! $item instanceof CommerceOrderItemSnapshotData) {
                throw new InvalidArgumentException(
                    'Commerce order snapshots may contain only CommerceOrderItemSnapshotData items.',
                );
            }
        }

        $externalItemIds = array_map(
            static fn (CommerceOrderItemSnapshotData $item): string => trim($item->externalId),
            $this->items,
        );

        if (count($externalItemIds) !== count(array_unique($externalItemIds))) {
            throw new InvalidArgumentException(
                'Commerce order snapshot item external identities must be unique within an order.',
            );
        }
    }
}