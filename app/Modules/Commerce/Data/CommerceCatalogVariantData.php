<?php

namespace App\Modules\Commerce\Data;

use App\Modules\Commerce\Models\CommerceProductVariant;
use InvalidArgumentException;

final readonly class CommerceCatalogVariantData
{
    /**
     * @param array<string, string> $options
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public CommerceProviderReferenceData $reference,
        public string $title,
        public string $status,
        public ?string $sku = null,
        public ?string $barcode = null,
        public array $options = [],
        public int $position = 0,
        public array $meta = [],
    ) {
        if (trim($this->title) === '') {
            throw new InvalidArgumentException(
                'Commerce catalog variant title cannot be empty.',
            );
        }

        if (! in_array($this->status, [
            CommerceProductVariant::STATUS_ACTIVE,
            CommerceProductVariant::STATUS_DRAFT,
            CommerceProductVariant::STATUS_ARCHIVED,
        ], true)) {
            throw new InvalidArgumentException(
                'Commerce catalog variant status is invalid.',
            );
        }

        if ($this->sku !== null && trim($this->sku) === '') {
            throw new InvalidArgumentException(
                'Commerce catalog variant SKU must be null or non-blank.',
            );
        }

        if ($this->barcode !== null && trim($this->barcode) === '') {
            throw new InvalidArgumentException(
                'Commerce catalog variant barcode must be null or non-blank.',
            );
        }

        if ($this->position < 0) {
            throw new InvalidArgumentException(
                'Commerce catalog variant position cannot be negative.',
            );
        }

        foreach ($this->options as $name => $value) {
            if (! is_string($name)
                || trim($name) === ''
                || ! is_string($value)
                || trim($value) === ''
            ) {
                throw new InvalidArgumentException(
                    'Commerce catalog variant options must use non-blank string names and values.',
                );
            }
        }
    }
}