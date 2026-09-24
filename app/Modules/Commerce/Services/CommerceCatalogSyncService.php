<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommerceCatalogProvider;
use App\Modules\Commerce\Data\CommerceCatalogPageRequest;
use App\Modules\Commerce\Data\CommerceCatalogProductData;
use App\Modules\Commerce\Data\CommerceCatalogSyncResult;
use App\Modules\Commerce\Data\CommerceCatalogVariantData;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CommerceCatalogSyncService
{
    public function __construct(
        private readonly CommerceProviderRoleResolver $roles,
    ) {}

    public function sync(
        ?string $scope = null,
        int $pageSize = 100,
    ): CommerceCatalogSyncResult {
        $provider = $this->roles->resolve(
            CommerceProviderRole::Catalog,
            $scope,
        );

        if (! $provider instanceof CommerceCatalogProvider) {
            throw new RuntimeException(
                'Configured Commerce catalog provider is invalid.',
            );
        }

        $providerKey = trim($provider->key());

        if ($providerKey === '') {
            throw new RuntimeException(
                'Commerce catalog provider key cannot be empty.',
            );
        }

        $cursor = null;
        $seenCursors = [];
        $pagesProcessed = 0;
        $productsCreated = 0;
        $productsUpdated = 0;
        $variantsCreated = 0;
        $variantsUpdated = 0;

        do {
            $page = $provider->catalogPage(new CommerceCatalogPageRequest(
                cursor: $cursor,
                limit: $pageSize,
                scope: $scope,
            ));

            $pagesProcessed++;

            foreach ($page->products as $productData) {
                $outcome = $this->persistProduct(
                    providerKey: $providerKey,
                    data: $productData,
                );

                $productsCreated += $outcome['product_created'];
                $productsUpdated += $outcome['product_updated'];
                $variantsCreated += $outcome['variants_created'];
                $variantsUpdated += $outcome['variants_updated'];
            }

            $nextCursor = $page->nextCursor;

            if ($nextCursor !== null) {
                if ($nextCursor === $cursor
                    || array_key_exists($nextCursor, $seenCursors)
                ) {
                    throw new RuntimeException(
                        'Commerce catalog provider returned a repeated page cursor.',
                    );
                }

                $seenCursors[$nextCursor] = true;
            }

            $cursor = $nextCursor;
        } while ($cursor !== null);

        return new CommerceCatalogSyncResult(
            providerKey: $providerKey,
            pagesProcessed: $pagesProcessed,
            productsCreated: $productsCreated,
            productsUpdated: $productsUpdated,
            variantsCreated: $variantsCreated,
            variantsUpdated: $variantsUpdated,
        );
    }

    /**
     * @return array{
     *     product_created:int,
     *     product_updated:int,
     *     variants_created:int,
     *     variants_updated:int
     * }
     */
    private function persistProduct(
        string $providerKey,
        CommerceCatalogProductData $data,
    ): array {
        return DB::transaction(function () use ($providerKey, $data): array {
            $mapping = CommerceProductProviderMapping::query()
                ->where('provider_key', $providerKey)
                ->where('reference_type', $data->reference->referenceType)
                ->where('external_id', $data->reference->externalId)
                ->lockForUpdate()
                ->first();

            $productCreated = $mapping === null;

            if ($mapping instanceof CommerceProductProviderMapping) {
                $product = CommerceProduct::withTrashed()->find(
                    $mapping->commerce_product_id,
                );

                if (! $product instanceof CommerceProduct) {
                    throw new RuntimeException(
                        'Commerce product provider mapping references a missing canonical product.',
                    );
                }
            } else {
                $product = new CommerceProduct();
            }

            if ($product->trashed()) {
                $product->restore();
            }

            $product->fill([
                'sku' => $data->sku,
                'name' => $data->name,
                'description' => $data->description,
                'status' => $data->status,
                'product_type' => $data->productType,
                'vendor' => $data->vendor,
                'category' => $data->category,
                'tags' => $data->tags !== [] ? $data->tags : null,
                'published_at' => $data->publishedAt,
                'source' => 'provider',
                'provider' => $providerKey,
                'external_id' => $data->reference->externalId,
                'external_url' => $data->reference->externalUrl,
                'meta' => $data->meta !== [] ? $data->meta : null,
            ]);
            $product->save();

            if (! $mapping instanceof CommerceProductProviderMapping) {
                $mapping = new CommerceProductProviderMapping([
                    'commerce_product_id' => $product->getKey(),
                    'provider_key' => $providerKey,
                    'reference_type' => $data->reference->referenceType,
                    'external_id' => $data->reference->externalId,
                ]);
            }

            $mapping->fill([
                'external_parent_id' => $data->reference->externalParentId,
                'external_url' => $data->reference->externalUrl,
                'status' => CommerceProductProviderMapping::STATUS_ACTIVE,
                'meta' => $data->reference->meta,
            ]);
            $mapping->save();

            $variantsCreated = 0;
            $variantsUpdated = 0;

            foreach ($data->variants as $variantData) {
                $created = $this->persistVariant(
                    providerKey: $providerKey,
                    product: $product,
                    productData: $data,
                    data: $variantData,
                );

                $created
                    ? $variantsCreated++
                    : $variantsUpdated++;
            }

            return [
                'product_created' => $productCreated ? 1 : 0,
                'product_updated' => $productCreated ? 0 : 1,
                'variants_created' => $variantsCreated,
                'variants_updated' => $variantsUpdated,
            ];
        });
    }

    private function persistVariant(
        string $providerKey,
        CommerceProduct $product,
        CommerceCatalogProductData $productData,
        CommerceCatalogVariantData $data,
    ): bool {
        $externalParentId = $data->reference->externalParentId;

        if ($externalParentId !== null
            && $externalParentId !== $productData->reference->externalId
        ) {
            throw new RuntimeException(
                'Commerce catalog variant provider parent identity does not match its product.',
            );
        }

        $mapping = CommerceProductVariantProviderMapping::query()
            ->where('provider_key', $providerKey)
            ->where('reference_type', $data->reference->referenceType)
            ->where('external_id', $data->reference->externalId)
            ->lockForUpdate()
            ->first();

        $variantCreated = $mapping === null;

        if ($mapping instanceof CommerceProductVariantProviderMapping) {
            $variant = CommerceProductVariant::withTrashed()->find(
                $mapping->commerce_product_variant_id,
            );

            if (! $variant instanceof CommerceProductVariant) {
                throw new RuntimeException(
                    'Commerce variant provider mapping references a missing canonical variant.',
                );
            }

            if ((int) $variant->commerce_product_id !== (int) $product->getKey()) {
                throw new RuntimeException(
                    'Commerce catalog variant provider identity cannot be silently re-parented to another canonical product.',
                );
            }
        } else {
            $variant = new CommerceProductVariant([
                'commerce_product_id' => $product->getKey(),
            ]);
        }

        if ($variant->trashed()) {
            $variant->restore();
        }

        $variant->fill([
            'commerce_product_id' => $product->getKey(),
            'sku' => $data->sku,
            'barcode' => $data->barcode,
            'title' => $data->title,
            'status' => $data->status,
            'options' => $data->options !== [] ? $data->options : null,
            'position' => $data->position,
            'meta' => $data->meta !== [] ? $data->meta : null,
        ]);
        $variant->save();

        if (! $mapping instanceof CommerceProductVariantProviderMapping) {
            $mapping = new CommerceProductVariantProviderMapping([
                'commerce_product_variant_id' => $variant->getKey(),
                'provider_key' => $providerKey,
                'reference_type' => $data->reference->referenceType,
                'external_id' => $data->reference->externalId,
            ]);
        }

        $mapping->fill([
            'external_parent_id' => $externalParentId
                ?? $productData->reference->externalId,
            'external_url' => $data->reference->externalUrl,
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
            'meta' => $data->reference->meta,
        ]);
        $mapping->save();

        return $variantCreated;
    }
}