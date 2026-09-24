<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceCatalogProvider;
use App\Modules\Commerce\Data\CommerceCatalogPage;
use App\Modules\Commerce\Data\CommerceCatalogPageRequest;
use App\Modules\Commerce\Data\CommerceCatalogProductData;
use App\Modules\Commerce\Data\CommerceCatalogVariantData;
use App\Modules\Commerce\Data\CommerceProviderReferenceData;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Services\CommerceCatalogSyncService;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CommerceCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_sync_creates_canonical_products_variants_and_explicit_provider_mappings(): void
    {
        $provider = new CatalogSyncFixtureProvider([
            '__first__' => new CommerceCatalogPage([
                $this->product(
                    productId: 'product-100',
                    name: 'Tour Shirt',
                    variants: [
                        $this->variant(
                            variantId: 'variant-101',
                            productId: 'product-100',
                            title: 'Small',
                            sku: 'TOUR-S',
                            position: 1,
                        ),
                        $this->variant(
                            variantId: 'variant-102',
                            productId: 'product-100',
                            title: 'Medium',
                            sku: 'TOUR-M',
                            position: 2,
                        ),
                    ],
                ),
            ], nextCursor: 'page-2'),
            'page-2' => new CommerceCatalogPage([
                $this->product(
                    productId: 'product-200',
                    name: 'Signed Poster',
                    variants: [
                        $this->variant(
                            variantId: 'variant-201',
                            productId: 'product-200',
                            title: 'Default',
                            sku: 'POSTER-SIGNED',
                        ),
                    ],
                ),
            ]),
        ]);

        $result = $this->service($provider)->sync(
            scope: 'online',
            pageSize: 50,
        );

        $this->assertSame('catalog-provider', $result->providerKey);
        $this->assertSame(2, $result->pagesProcessed);
        $this->assertSame(2, $result->productsCreated);
        $this->assertSame(0, $result->productsUpdated);
        $this->assertSame(3, $result->variantsCreated);
        $this->assertSame(0, $result->variantsUpdated);

        $this->assertCount(2, $provider->requests);
        $this->assertNull($provider->requests[0]->cursor);
        $this->assertSame('page-2', $provider->requests[1]->cursor);
        $this->assertSame(50, $provider->requests[0]->limit);
        $this->assertSame('online', $provider->requests[0]->scope);

        $product = CommerceProduct::query()
            ->where('external_id', 'product-100')
            ->firstOrFail();

        $this->assertSame('Tour Shirt', $product->name);
        $this->assertSame('catalog-provider', $product->provider);
        $this->assertSame(['apparel', 'tour'], $product->tags);
        $this->assertSame('2026-09-01 12:00:00', $product->published_at?->format('Y-m-d H:i:s'));

        $productMapping = CommerceProductProviderMapping::query()
            ->where('provider_key', 'catalog-provider')
            ->where('reference_type', 'catalog_product')
            ->where('external_id', 'product-100')
            ->firstOrFail();

        $this->assertSame((int) $product->getKey(), (int) $productMapping->commerce_product_id);

        $variant = CommerceProductVariant::query()
            ->where('commerce_product_id', $product->getKey())
            ->where('sku', 'TOUR-M')
            ->firstOrFail();

        $this->assertSame('Medium', $variant->title);
        $this->assertSame(['Size' => 'Medium'], $variant->options);
        $this->assertSame(2, $variant->position);

        $variantMapping = CommerceProductVariantProviderMapping::query()
            ->where('provider_key', 'catalog-provider')
            ->where('reference_type', 'product_variant')
            ->where('external_id', 'variant-102')
            ->firstOrFail();

        $this->assertSame((int) $variant->getKey(), (int) $variantMapping->commerce_product_variant_id);
        $this->assertSame('product-100', $variantMapping->external_parent_id);
    }

    public function test_catalog_sync_updates_by_explicit_provider_identity_without_using_sku_as_identity(): void
    {
        $unmappedProduct = CommerceProduct::factory()->active()->create([
            'name' => 'Unrelated local product',
        ]);
        $unmappedVariant = CommerceProductVariant::factory()
            ->for($unmappedProduct, 'commerceProduct')
            ->active()
            ->create([
                'sku' => 'SHARED-SKU',
                'title' => 'Unrelated',
            ]);

        $firstProvider = new CatalogSyncFixtureProvider([
            '__first__' => new CommerceCatalogPage([
                $this->product(
                    productId: 'product-100',
                    name: 'Original Product',
                    variants: [
                        $this->variant(
                            variantId: 'variant-101',
                            productId: 'product-100',
                            title: 'Original Variant',
                            sku: 'SHARED-SKU',
                        ),
                    ],
                ),
            ]),
        ]);

        $first = $this->service($firstProvider)->sync();

        $productMapping = CommerceProductProviderMapping::query()
            ->where('provider_key', 'catalog-provider')
            ->where('external_id', 'product-100')
            ->firstOrFail();
        $variantMapping = CommerceProductVariantProviderMapping::query()
            ->where('provider_key', 'catalog-provider')
            ->where('external_id', 'variant-101')
            ->firstOrFail();

        $productId = (int) $productMapping->commerce_product_id;
        $variantId = (int) $variantMapping->commerce_product_variant_id;

        $secondProvider = new CatalogSyncFixtureProvider([
            '__first__' => new CommerceCatalogPage([
                $this->product(
                    productId: 'product-100',
                    name: 'Updated Product',
                    variants: [
                        $this->variant(
                            variantId: 'variant-101',
                            productId: 'product-100',
                            title: 'Updated Variant',
                            sku: 'NEW-SKU',
                        ),
                    ],
                ),
            ]),
        ]);

        $second = $this->service($secondProvider)->sync();

        $this->assertSame(1, $first->productsCreated);
        $this->assertSame(1, $first->variantsCreated);
        $this->assertSame(0, $second->productsCreated);
        $this->assertSame(1, $second->productsUpdated);
        $this->assertSame(0, $second->variantsCreated);
        $this->assertSame(1, $second->variantsUpdated);

        $this->assertSame('Updated Product', CommerceProduct::query()->findOrFail($productId)->name);
        $this->assertSame('Updated Variant', CommerceProductVariant::query()->findOrFail($variantId)->title);
        $this->assertSame('NEW-SKU', CommerceProductVariant::query()->findOrFail($variantId)->sku);

        $this->assertSame('Unrelated', $unmappedVariant->fresh()->title);
        $this->assertSame('SHARED-SKU', $unmappedVariant->fresh()->sku);
        $this->assertSame(2, CommerceProductVariant::query()->count());
    }

    public function test_catalog_sync_does_not_archive_rows_that_are_absent_from_a_later_partial_fetch(): void
    {
        $provider = new CatalogSyncFixtureProvider([
            '__first__' => new CommerceCatalogPage([
                $this->product(
                    productId: 'product-100',
                    name: 'Persistent Product',
                    variants: [
                        $this->variant(
                            variantId: 'variant-101',
                            productId: 'product-100',
                            title: 'Persistent Variant',
                        ),
                    ],
                ),
            ]),
        ]);

        $this->service($provider)->sync();

        $emptyProvider = new CatalogSyncFixtureProvider([
            '__first__' => new CommerceCatalogPage([]),
        ]);

        $result = $this->service($emptyProvider)->sync();

        $this->assertSame(0, $result->productsProcessed());
        $this->assertSame(0, $result->variantsProcessed());

        $this->assertSame(
            CommerceProduct::STATUS_ACTIVE,
            CommerceProduct::query()->firstOrFail()->status,
        );
        $this->assertSame(
            CommerceProductVariant::STATUS_ACTIVE,
            CommerceProductVariant::query()->firstOrFail()->status,
        );
        $this->assertSame(
            CommerceProductProviderMapping::STATUS_ACTIVE,
            CommerceProductProviderMapping::query()->firstOrFail()->status,
        );
        $this->assertSame(
            CommerceProductVariantProviderMapping::STATUS_ACTIVE,
            CommerceProductVariantProviderMapping::query()->firstOrFail()->status,
        );
    }

    public function test_catalog_sync_rejects_silent_variant_reparenting_between_canonical_products(): void
    {
        $firstProvider = new CatalogSyncFixtureProvider([
            '__first__' => new CommerceCatalogPage([
                $this->product(
                    productId: 'product-100',
                    name: 'First Product',
                    variants: [
                        $this->variant(
                            variantId: 'variant-shared',
                            productId: 'product-100',
                            title: 'First Variant',
                        ),
                    ],
                ),
            ]),
        ]);

        $this->service($firstProvider)->sync();

        $secondProvider = new CatalogSyncFixtureProvider([
            '__first__' => new CommerceCatalogPage([
                $this->product(
                    productId: 'product-200',
                    name: 'Second Product',
                    variants: [
                        $this->variant(
                            variantId: 'variant-shared',
                            productId: 'product-200',
                            title: 'Moved Variant',
                        ),
                    ],
                ),
            ]),
        ]);

        try {
            $this->service($secondProvider)->sync();
            $this->fail('Expected variant reparenting to be rejected.');
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(1, CommerceProduct::query()->count());
        $this->assertDatabaseMissing('commerce_product_provider_mappings', [
            'provider_key' => 'catalog-provider',
            'external_id' => 'product-200',
        ]);
    }

    public function test_catalog_sync_rejects_repeated_provider_page_cursor(): void
    {
        $provider = new CatalogSyncFixtureProvider([
            '__first__' => new CommerceCatalogPage([], nextCursor: 'again'),
            'again' => new CommerceCatalogPage([], nextCursor: 'again'),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service($provider)->sync();
    }

    private function service(
        CommerceCatalogProvider $provider,
    ): CommerceCatalogSyncService {
        $resolver = new CommerceProviderRoleResolver(
            providers: new CommerceProviderRegistry([$provider]),
            config: new Repository([
                'commerce' => [
                    'provider_roles' => [
                        CommerceProviderRole::Catalog->value => [
                            'default' => $provider->key(),
                            'scopes' => [],
                        ],
                    ],
                ],
            ]),
        );

        return new CommerceCatalogSyncService($resolver);
    }

    /** @param array<int, CommerceCatalogVariantData> $variants */
    private function product(
        string $productId,
        string $name,
        array $variants,
    ): CommerceCatalogProductData {
        return new CommerceCatalogProductData(
            reference: new CommerceProviderReferenceData(
                referenceType: 'catalog_product',
                externalId: $productId,
                externalUrl: 'https://provider.example.test/products/'.$productId,
                meta: ['source' => 'fixture'],
            ),
            name: $name,
            status: CommerceProduct::STATUS_ACTIVE,
            variants: $variants,
            description: 'Provider catalog description',
            productType: 'Merchandise',
            vendor: 'Example Vendor',
            category: 'Apparel',
            tags: ['apparel', 'tour'],
            publishedAt: new DateTimeImmutable('2026-09-01T12:00:00+00:00'),
            meta: ['normalized' => true],
        );
    }

    private function variant(
        string $variantId,
        string $productId,
        string $title,
        ?string $sku = null,
        int $position = 0,
    ): CommerceCatalogVariantData {
        return new CommerceCatalogVariantData(
            reference: new CommerceProviderReferenceData(
                referenceType: 'product_variant',
                externalId: $variantId,
                externalParentId: $productId,
                externalUrl: 'https://provider.example.test/variants/'.$variantId,
                meta: ['source' => 'fixture'],
            ),
            title: $title,
            status: CommerceProductVariant::STATUS_ACTIVE,
            sku: $sku,
            barcode: null,
            options: $title === 'Default'
                ? []
                : ['Size' => $title],
            position: $position,
            meta: ['normalized' => true],
        );
    }
}

final class CatalogSyncFixtureProvider implements CommerceCatalogProvider
{
    /** @var array<int, CommerceCatalogPageRequest> */
    public array $requests = [];

    /** @param array<string, CommerceCatalogPage> $pages */
    public function __construct(
        private readonly array $pages,
        private readonly string $providerKey = 'catalog-provider',
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function catalogPage(
        CommerceCatalogPageRequest $request,
    ): CommerceCatalogPage {
        $this->requests[] = $request;
        $key = $request->cursor ?? '__first__';
        $page = $this->pages[$key] ?? null;

        if (! $page instanceof CommerceCatalogPage) {
            throw new RuntimeException(
                "No fixture catalog page exists for cursor [{$key}].",
            );
        }

        return $page;
    }
}