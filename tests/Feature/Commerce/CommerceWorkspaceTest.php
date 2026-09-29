<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['commerce']);
        config()->set('commerce.provider_roles.'.CommerceProviderRole::Catalog->value, [
            'default' => 'shopify',
            'scopes' => [],
        ]);
        config()->set('commerce.provider_roles.'.CommerceProviderRole::Inventory->value, [
            'default' => 'shopify',
            'scopes' => [],
        ]);
    }

    public function test_workspace_summarizes_catalog_and_provider_mapping_coverage(): void
    {
        $product = CommerceProduct::factory()->active()->create([
            'name' => 'Tour Shirt',
            'provider' => 'shopify',
            'external_id' => 'product-100',
        ]);
        $mappedVariant = CommerceProductVariant::factory()
            ->for($product, 'commerceProduct')
            ->active()
            ->create(['title' => 'Medium']);
        CommerceProductVariant::factory()
            ->for($product, 'commerceProduct')
            ->active()
            ->create(['title' => 'Large']);

        CommerceProductProviderMapping::query()->create([
            'commerce_product_id' => $product->getKey(),
            'provider_key' => 'shopify',
            'reference_type' => 'catalog_product',
            'external_id' => 'product-100',
            'status' => CommerceProductProviderMapping::STATUS_ACTIVE,
        ]);
        CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $mappedVariant->getKey(),
            'provider_key' => 'shopify',
            'reference_type' => 'product_variant',
            'external_id' => 'variant-200',
            'external_parent_id' => 'product-100',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.commerce.index'))
            ->assertOk()
            ->assertViewIs('crm.commerce.index');

        $overview = $response->viewData('overview');
        $products = $response->viewData('products');

        $this->assertSame(1, $overview['product_count']);
        $this->assertSame(2, $overview['active_variant_count']);
        $this->assertSame('shopify', $overview['role_bindings']['catalog']['default']);
        $this->assertSame(1, $overview['mapping_coverage']['catalog']['mapped_variant_count']);
        $this->assertSame(1, $overview['mapping_coverage']['catalog']['unmapped_variant_count']);
        $this->assertSame(1, $products->total());
        $this->assertSame((int) $product->getKey(), (int) $products->first()->getKey());
    }

    public function test_product_detail_exposes_variants_mappings_and_latest_inventory_evidence(): void
    {
        $product = CommerceProduct::factory()->active()->create([
            'name' => 'Tour Shirt',
        ]);
        $variant = CommerceProductVariant::factory()
            ->for($product, 'commerceProduct')
            ->active()
            ->create([
                'title' => 'Medium',
                'sku' => 'SHIRT-M',
            ]);

        $mapping = CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => 'shopify',
            'reference_type' => 'product_variant',
            'external_id' => 'variant-200',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        $older = $this->inventoryEffect($variant, '-1', 'older-effect');
        $latest = $this->inventoryEffect($variant, '-2', 'latest-effect');

        $detail = $this->actingAs(User::factory()->create())
            ->get(route('crm.commerce.products.show', $product))
            ->assertOk()
            ->assertViewIs('crm.commerce.products.show')
            ->viewData('detail');

        $this->assertSame((int) $product->getKey(), (int) $detail['product']->getKey());
        $this->assertSame(1, $detail['variants']->count());
        $this->assertSame((int) $mapping->getKey(), (int) $detail['variants']->first()->providerMappings->first()->getKey());
        $this->assertSame(
            (int) $latest->getKey(),
            (int) $detail['latest_inventory_effects'][$variant->getKey()]->getKey(),
        );
        $this->assertNotSame((int) $older->getKey(), (int) $latest->getKey());
    }

    public function test_commerce_navigation_is_exposed_only_when_commerce_is_enabled(): void
    {
        $enabled = collect(app(ModuleManager::class)->navigationItems())
            ->firstWhere('module', 'commerce');

        $this->assertIsArray($enabled);
        $this->assertSame('crm.commerce.index', $enabled['route']);

        config()->set('modules.enabled', []);

        $disabled = collect(app(ModuleManager::class)->navigationItems())
            ->firstWhere('module', 'commerce');

        $this->assertNull($disabled);
    }

    private function inventoryEffect(
        CommerceProductVariant $variant,
        string $quantityDelta,
        string $idempotencyKey,
    ): CommerceInventoryEffect {
        return CommerceInventoryEffect::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'source_type' => 'provider',
            'source_key' => 'shopify',
            'source_reference' => 'order-100',
            'reason' => 'completed_sale',
            'quantity_delta' => $quantityDelta,
            'authority_mode' => CommerceInventoryAuthorityMode::AuthorityAlreadyApplied,
            'inventory_scope' => null,
            'status' => CommerceInventoryEffect::STATUS_RECONCILED,
            'idempotency_key' => $idempotencyKey,
            'payload_fingerprint' => hash('sha256', $idempotencyKey),
            'occurred_at' => now(),
        ]);
    }
}