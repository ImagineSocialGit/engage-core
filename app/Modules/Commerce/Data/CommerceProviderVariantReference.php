<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceProviderVariantReference
{
    /** @param array<int, CommerceProviderReferenceData> $references */
    public function __construct(
        public int $commerceProductId,
        public int $commerceProductVariantId,
        public string $providerKey,
        public array $references,
        public ?string $sku = null,
        public ?string $barcode = null,
    ) {
        if ($this->commerceProductId < 1 || $this->commerceProductVariantId < 1) {
            throw new InvalidArgumentException('Commerce canonical product and variant identities must be positive integers.');
        }

        if (trim($this->providerKey) === '') {
            throw new InvalidArgumentException('Commerce provider key cannot be empty.');
        }

        if ($this->references === []) {
            throw new InvalidArgumentException('Commerce provider variant reference requires at least one explicit provider mapping.');
        }

        foreach ($this->references as $reference) {
            if (! $reference instanceof CommerceProviderReferenceData) {
                throw new InvalidArgumentException('Commerce provider variant references must use CommerceProviderReferenceData values.');
            }
        }
    }

    public function reference(string $referenceType): ?CommerceProviderReferenceData
    {
        foreach ($this->references as $reference) {
            if ($reference->referenceType === $referenceType) {
                return $reference;
            }
        }

        return null;
    }
}