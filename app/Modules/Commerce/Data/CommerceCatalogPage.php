<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceCatalogPage
{
    /** @param array<int, CommerceCatalogProductData> $products */
    public function __construct(
        public array $products,
        public ?string $nextCursor = null,
    ) {
        foreach ($this->products as $product) {
            if (! $product instanceof CommerceCatalogProductData) {
                throw new InvalidArgumentException(
                    'Commerce catalog pages must contain CommerceCatalogProductData values.',
                );
            }
        }

        if ($this->nextCursor !== null && trim($this->nextCursor) === '') {
            throw new InvalidArgumentException(
                'Commerce catalog next cursor must be null or a non-blank string.',
            );
        }
    }
}