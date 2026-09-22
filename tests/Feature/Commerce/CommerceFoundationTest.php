<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceCatalogProvider;
use App\Modules\Commerce\Contracts\CommerceInventoryProvider;
use App\Modules\Commerce\Contracts\CommercePointOfSaleProvider;
use App\Modules\Commerce\Data\CommerceInventoryEffectData;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Providers\CommerceModuleServiceProvider;
use App\Modules\Commerce\Services\CommerceInventoryEffectRecorder;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use App\Modules\Core\Models\Contact;
use App\Support\Modules\ModuleManager;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CommerceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_commerce_module_is_registered_without_being_enabled_by_default(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'campaigns',
            'broadcasts',
            'webinars',
            'integrations',
            'reporting',
        ]);

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('commerce'));
        $this->assertFalse($modules->enabled('commerce'));
        $this->assertSame(['core'], $modules->dependencies('commerce'));
        $this->assertContains(CommerceModuleServiceProvider::class, $modules->providers('commerce'));
    }

    public function test_canonical_variant_can_map_to_multiple_provider_identities_and_purchase_history(): void
    {
        $contact = Contact::factory()->create();
        $customer = CommerceCustomer::factory()->forContact($contact)->create([
            'provider' => 'order-provider',
            'external_id' => 'customer-1001',
        ]);
        $product = CommerceProduct::factory()->active()->create([
            'name' => 'Classic T-shirt',
            'sku' => 'TSHIRT-CLASSIC',
        ]);
        $variant = CommerceProductVariant::factory()->for($product, 'commerceProduct')->create([
            'key' => 'medium',
            'sku' => 'TSHIRT-CLASSIC-M',
            'barcode' => '0123456789012',
            'title' => 'Medium',
            'options' => ['Size' => 'Medium'],
        ]);

        CommerceProductProviderMapping::query()->create([
            'commerce_product_id' => $product->getKey(),
            'provider_key' => 'catalog-provider',
            'reference_type' => 'catalog_product',
            'external_id' => 'product-2001',
        ]);

        CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => 'catalog-provider',
            'reference_type' => 'catalog_variant',
            'external_id' => 'variant-5001',
            'external_parent_id' => 'product-2001',
        ]);

        CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => 'pos-provider',
            'reference_type' => 'pos_variation',
            'external_id' => 'variation-9001',
        ]);

        $order = CommerceOrder::factory()
            ->forCustomer($customer)
            ->paid()
            ->create([
                'provider' => 'order-provider',
                'external_id' => 'order-3001',
            ]);

        $item = CommerceOrderItem::factory()->create([
            'commerce_order_id' => $order->getKey(),
            'commerce_product_id' => $product->getKey(),
            'commerce_product_variant_id' => $variant->getKey(),
            'sku' => 'TSHIRT-CLASSIC-M',
            'external_product_id' => 'product-2001',
            'external_variant_id' => 'variant-5001',
        ]);

        $event = CommerceOrderEvent::factory()->actor($contact)->create([
            'commerce_order_id' => $order->getKey(),
            'event' => CommerceOrderEvent::EVENT_PAID,
            'to_status' => CommerceOrder::STATUS_CLOSED,
        ]);

        $this->assertTrue($customer->contact->is($contact));
        $this->assertTrue($order->items->contains($item));
        $this->assertTrue($order->events->contains($event));
        $this->assertTrue($item->commerceProduct->is($product));
        $this->assertTrue($item->commerceProductVariant->is($variant));
        $this->assertCount(2, $variant->providerMappings);
        $this->assertEqualsCanonicalizing(
            ['catalog-provider', 'pos-provider'],
            $variant->providerMappings->pluck('provider_key')->all(),
        );
    }

    public function test_provider_roles_resolve_independently_and_support_scoped_overrides(): void
    {
        $catalog = new CatalogInventoryTestProvider('catalog-provider');
        $inventory = new CatalogInventoryTestProvider('inventory-provider');
        $pos = new PointOfSaleTestProvider('pos-provider');

        $registry = new CommerceProviderRegistry([
            $catalog,
            $inventory,
            $pos,
        ]);

        $resolver = new CommerceProviderRoleResolver(
            providers: $registry,
            config: new Repository([
                'commerce' => [
                    'provider_roles' => [
                        CommerceProviderRole::Catalog->value => [
                            'default' => 'catalog-provider',
                            'scopes' => [],
                        ],
                        CommerceProviderRole::Inventory->value => [
                            'default' => 'inventory-provider',
                            'scopes' => [
                                'venue' => 'catalog-provider',
                            ],
                        ],
                        CommerceProviderRole::PointOfSale->value => [
                            'default' => 'pos-provider',
                            'scopes' => [],
                        ],
                    ],
                ],
            ]),
        );

        $this->assertSame($catalog, $resolver->resolve(CommerceProviderRole::Catalog));
        $this->assertSame($inventory, $resolver->resolve(CommerceProviderRole::Inventory));
        $this->assertSame($catalog, $resolver->resolve(CommerceProviderRole::Inventory, 'venue'));
        $this->assertSame($pos, $resolver->resolve(CommerceProviderRole::PointOfSale));
    }

    public function test_provider_role_resolution_fails_when_provider_lacks_required_capability(): void
    {
        $registry = new CommerceProviderRegistry([
            new PointOfSaleTestProvider('pos-provider'),
        ]);

        $resolver = new CommerceProviderRoleResolver(
            providers: $registry,
            config: new Repository([
                'commerce' => [
                    'provider_roles' => [
                        CommerceProviderRole::Inventory->value => [
                            'default' => 'pos-provider',
                            'scopes' => [],
                        ],
                    ],
                ],
            ]),
        );

        $this->expectException(RuntimeException::class);

        $resolver->resolve(CommerceProviderRole::Inventory);
    }

    public function test_inventory_effect_recording_is_idempotent_and_preserves_authority_decision(): void
    {
        $variant = CommerceProductVariant::factory()->create();
        $recorder = app(CommerceInventoryEffectRecorder::class);

        $data = new CommerceInventoryEffectData(
            commerceProductVariantId: (int) $variant->getKey(),
            quantityDelta: '-1',
            reason: 'completed_sale',
            sourceType: 'provider',
            sourceKey: 'pos-provider',
            idempotencyKey: 'pos-provider:sale-123:variant-1',
            authorityMode: CommerceInventoryAuthorityMode::AdjustmentRequired,
            sourceReference: 'sale-123',
            inventoryScope: 'default',
        );

        $first = $recorder->record($data);
        $second = $recorder->record($data);

        $this->assertTrue($first->is($second));
        $this->assertTrue($first->requiresAuthorityAdjustment());
        $this->assertFalse($first->authorityAlreadyApplied());
        $this->assertSame('-1.0000', $first->quantity_delta);
        $this->assertSame(1, $variant->inventoryEffects()->count());
    }

    public function test_inventory_effect_idempotency_key_cannot_be_reused_for_a_different_effect(): void
    {
        $variant = CommerceProductVariant::factory()->create();
        $recorder = app(CommerceInventoryEffectRecorder::class);

        $base = new CommerceInventoryEffectData(
            commerceProductVariantId: (int) $variant->getKey(),
            quantityDelta: '-1',
            reason: 'completed_sale',
            sourceType: 'provider',
            sourceKey: 'pos-provider',
            idempotencyKey: 'pos-provider:sale-456:variant-1',
            authorityMode: CommerceInventoryAuthorityMode::AuthorityAlreadyApplied,
            sourceReference: 'sale-456',
        );

        $recorder->record($base);

        $this->expectException(RuntimeException::class);

        $recorder->record(new CommerceInventoryEffectData(
            commerceProductVariantId: (int) $variant->getKey(),
            quantityDelta: '-2',
            reason: 'completed_sale',
            sourceType: 'provider',
            sourceKey: 'pos-provider',
            idempotencyKey: 'pos-provider:sale-456:variant-1',
            authorityMode: CommerceInventoryAuthorityMode::AuthorityAlreadyApplied,
            sourceReference: 'sale-456',
        ));
    }
}

final readonly class CatalogInventoryTestProvider implements CommerceCatalogProvider, CommerceInventoryProvider
{
    public function __construct(
        private string $providerKey,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }
}

final readonly class PointOfSaleTestProvider implements CommercePointOfSaleProvider
{
    public function __construct(
        private string $providerKey,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }
}