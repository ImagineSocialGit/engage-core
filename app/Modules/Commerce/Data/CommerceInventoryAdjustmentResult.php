<?php

namespace App\Modules\Commerce\Data;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class CommerceInventoryAdjustmentResult
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $providerKey,
        public string $idempotencyKey,
        public ?string $externalId = null,
        public ?DateTimeInterface $completedAt = null,
        public array $meta = [],
    ) {
        if (trim($this->providerKey) === '') {
            throw new InvalidArgumentException(
                'Commerce inventory adjustment result provider key cannot be empty.',
            );
        }

        if (trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'Commerce inventory adjustment result idempotency key cannot be empty.',
            );
        }
    }
}