<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Data\CommerceProviderReferenceData;
use App\Modules\Commerce\Data\CommerceProviderVariantReference;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use RuntimeException;

final class CommerceProviderVariantReferenceResolver
{
    public function resolve(
        CommerceProductVariant $variant,
        string $providerKey,
    ): CommerceProviderVariantReference {
        $providerKey = trim($providerKey);

        if ($providerKey === '') {
            throw new RuntimeException('Commerce provider key is required to resolve a variant mapping.');
        }

        $mappings = CommerceProductVariantProviderMapping::query()
            ->where('commerce_product_variant_id', $variant->getKey())
            ->where('provider_key', $providerKey)
            ->where('status', CommerceProductVariantProviderMapping::STATUS_ACTIVE)
            ->orderBy('reference_type')
            ->orderBy('id')
            ->get();

        if ($mappings->isEmpty()) {
            throw new RuntimeException('Commerce variant has no active explicit mapping for the requested provider.');
        }

        return new CommerceProviderVariantReference(
            commerceProductId: (int) $variant->commerce_product_id,
            commerceProductVariantId: (int) $variant->getKey(),
            providerKey: $providerKey,
            references: $mappings
                ->map(static fn (CommerceProductVariantProviderMapping $mapping): CommerceProviderReferenceData => new CommerceProviderReferenceData(
                    referenceType: $mapping->reference_type,
                    externalId: $mapping->external_id,
                    externalParentId: $mapping->external_parent_id,
                    externalUrl: $mapping->external_url,
                    meta: $mapping->meta,
                ))
                ->all(),
            sku: $variant->sku,
            barcode: $variant->barcode,
        );
    }
}