<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceProviderReferenceData
{
    /** @param array<string, mixed>|null $meta */
    public function __construct(
        public string $referenceType,
        public string $externalId,
        public ?string $externalParentId = null,
        public ?string $externalUrl = null,
        public ?array $meta = null,
    ) {
        if (trim($this->referenceType) === '') {
            throw new InvalidArgumentException('Commerce provider reference type cannot be empty.');
        }

        if (trim($this->externalId) === '') {
            throw new InvalidArgumentException('Commerce provider external identity cannot be empty.');
        }
    }
}