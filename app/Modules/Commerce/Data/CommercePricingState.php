<?php

namespace App\Modules\Commerce\Data;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CommercePricingState
{
    public function __construct(
        public string $providerKey,
        public string $currency,
        public int $unitPriceCents,
        public ?int $compareAtPriceCents = null,
        public bool $availableForSale = true,
        public ?DateTimeImmutable $refreshedAt = null,
    ) {
        if (trim($this->providerKey) === '') {
            throw new InvalidArgumentException('Commerce pricing provider key cannot be empty.');
        }

        if (preg_match('/^[A-Z]{3}$/D', strtoupper(trim($this->currency))) !== 1) {
            throw new InvalidArgumentException('Commerce pricing currency must be a three-letter currency code.');
        }

        if ($this->unitPriceCents < 0 || ($this->compareAtPriceCents !== null && $this->compareAtPriceCents < 0)) {
            throw new InvalidArgumentException('Commerce pricing cents cannot be negative.');
        }
    }

    public function onSale(): bool
    {
        return $this->compareAtPriceCents !== null
            && $this->compareAtPriceCents > $this->unitPriceCents;
    }
}