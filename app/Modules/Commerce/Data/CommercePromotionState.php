<?php

namespace App\Modules\Commerce\Data;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CommercePromotionState
{
    /** @param array<string, mixed> $checkoutContext */
    public function __construct(
        public string $providerKey,
        public bool $active,
        public ?string $label = null,
        public ?string $providerReference = null,
        public array $checkoutContext = [],
        public ?DateTimeImmutable $refreshedAt = null,
    ) {
        if (trim($this->providerKey) === '') {
            throw new InvalidArgumentException('Commerce promotion provider key cannot be empty.');
        }
    }
}