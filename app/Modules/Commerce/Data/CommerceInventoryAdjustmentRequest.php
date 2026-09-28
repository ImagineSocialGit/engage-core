<?php

namespace App\Modules\Commerce\Data;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class CommerceInventoryAdjustmentRequest
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public int $commerceInventoryEffectId,
        public CommerceProviderVariantReference $variant,
        public string $quantityDelta,
        public string $reason,
        public string $idempotencyKey,
        public string $sourceType,
        public string $sourceKey,
        public ?string $sourceReference = null,
        public ?string $inventoryScope = null,
        public ?DateTimeInterface $occurredAt = null,
        public array $meta = [],
    ) {
        if ($this->commerceInventoryEffectId < 1) {
            throw new InvalidArgumentException(
                'Commerce inventory adjustment requires a persisted inventory effect.',
            );
        }

        $quantity = trim($this->quantityDelta);

        if (preg_match('/^-?\d{1,8}(?:\.\d{1,4})?$/D', $quantity) !== 1) {
            throw new InvalidArgumentException(
                'Commerce inventory adjustment quantity delta must fit a decimal(12,4) value.',
            );
        }

        if ((float) $quantity === 0.0) {
            throw new InvalidArgumentException(
                'Commerce inventory adjustment quantity delta cannot be zero.',
            );
        }

        foreach ([
            'reason' => $this->reason,
            'idempotency key' => $this->idempotencyKey,
            'source type' => $this->sourceType,
            'source key' => $this->sourceKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(
                    "Commerce inventory adjustment {$field} cannot be empty.",
                );
            }
        }
    }
}