<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceInventoryState
{
    public function __construct(
        public string $providerKey,
        public int $commerceProductVariantId,
        public bool $tracked,
        public ?int $availableQuantity,
        public string $externalInventoryItemId,
        public string $externalLocationId,
    ) {
        if (trim($this->providerKey) === ''
            || $this->commerceProductVariantId < 1
            || trim($this->externalInventoryItemId) === ''
            || trim($this->externalLocationId) === ''
            || ($this->tracked && $this->availableQuantity === null)
            || (! $this->tracked && $this->availableQuantity !== null)
        ) {
            throw new InvalidArgumentException('Commerce inventory state is invalid.');
        }
    }
}