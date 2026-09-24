<?php

namespace App\Modules\Commerce\Data;

use App\Modules\Commerce\Models\CommerceProduct;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CommerceCatalogProductData
{
    /**
     * @param array<int, string> $tags
     * @param array<int, CommerceCatalogVariantData> $variants
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public CommerceProviderReferenceData $reference,
        public string $name,
        public string $status,
        public array $variants,
        public ?string $description = null,
        public ?string $sku = null,
        public ?string $productType = null,
        public ?string $vendor = null,
        public ?string $category = null,
        public array $tags = [],
        public ?DateTimeImmutable $publishedAt = null,
        public array $meta = [],
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException(
                'Commerce catalog product name cannot be empty.',
            );
        }

        if (! in_array($this->status, [
            CommerceProduct::STATUS_ACTIVE,
            CommerceProduct::STATUS_DRAFT,
            CommerceProduct::STATUS_ARCHIVED,
        ], true)) {
            throw new InvalidArgumentException(
                'Commerce catalog product status is invalid.',
            );
        }

        foreach ([
            'description' => $this->description,
            'sku' => $this->sku,
            'product type' => $this->productType,
            'vendor' => $this->vendor,
            'category' => $this->category,
        ] as $label => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException(
                    "Commerce catalog product {$label} must be null or non-blank.",
                );
            }
        }

        foreach ($this->tags as $tag) {
            if (! is_string($tag) || trim($tag) === '') {
                throw new InvalidArgumentException(
                    'Commerce catalog product tags must be non-blank strings.',
                );
            }
        }

        foreach ($this->variants as $variant) {
            if (! $variant instanceof CommerceCatalogVariantData) {
                throw new InvalidArgumentException(
                    'Commerce catalog product variants must use CommerceCatalogVariantData values.',
                );
            }
        }
    }
}