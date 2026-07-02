<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Providers\CommerceModuleServiceProvider;
use App\Modules\Core\Models\Contact;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_commerce_foundation_tables_have_durable_provider_sync_columns(): void
    {
        $this->assertTableHasColumns('commerce_customers', [
            'contact_id',
            'first_name',
            'last_name',
            'name',
            'email',
            'phone',
            'status',
            'currency',
            'first_ordered_at',
            'last_ordered_at',
            'total_orders',
            'total_spent_cents',
            'source',
            'provider',
            'external_id',
            'external_url',
            'raw_payload',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('commerce_products', [
            'key',
            'sku',
            'name',
            'description',
            'status',
            'product_type',
            'vendor',
            'category',
            'tags',
            'currency',
            'price_cents',
            'published_at',
            'source',
            'provider',
            'external_id',
            'external_url',
            'raw_payload',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('commerce_orders', [
            'commerce_customer_id',
            'contact_id',
            'order_number',
            'order_name',
            'status',
            'financial_status',
            'fulfillment_status',
            'currency',
            'subtotal_cents',
            'discount_cents',
            'tax_cents',
            'shipping_cents',
            'total_cents',
            'ordered_at',
            'closed_at',
            'cancelled_at',
            'refunded_at',
            'source',
            'provider',
            'external_id',
            'external_url',
            'raw_payload',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('commerce_order_items', [
            'commerce_order_id',
            'commerce_product_id',
            'item_type',
            'sku',
            'name',
            'title',
            'variant_title',
            'options',
            'quantity',
            'currency',
            'unit_price_cents',
            'discount_cents',
            'tax_cents',
            'total_cents',
            'fulfillment_status',
            'source',
            'provider',
            'external_id',
            'external_product_id',
            'external_variant_id',
            'external_url',
            'raw_payload',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('commerce_order_events', [
            'commerce_order_id',
            'actor_type',
            'actor_id',
            'event',
            'from_status',
            'to_status',
            'occurred_at',
            'source',
            'provider',
            'external_id',
            'payload',
            'meta',
            'deleted_at',
        ]);
    }

    public function test_commerce_order_history_can_link_contacts_to_prior_product_purchases(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $customer = CommerceCustomer::factory()->forContact($contact)->create([
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Customer/1001',
        ]);

        $product = CommerceProduct::factory()->active()->create([
            'name' => 'Classic T-shirt',
            'sku' => 'TSHIRT-CLASSIC',
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Product/2001',
            'tags' => ['t-shirt', 'apparel'],
        ]);

        $order = CommerceOrder::factory()
            ->forCustomer($customer)
            ->paid()
            ->create([
                'order_number' => '1001',
                'order_name' => '#1001',
                'total_cents' => 2500,
                'provider' => 'shopify',
                'external_id' => 'gid://shopify/Order/3001',
            ]);

        $item = CommerceOrderItem::factory()->create([
            'commerce_order_id' => $order->id,
            'commerce_product_id' => $product->id,
            'sku' => 'TSHIRT-CLASSIC-M',
            'name' => 'Classic T-shirt',
            'title' => 'Classic T-shirt',
            'variant_title' => 'Medium',
            'external_product_id' => 'gid://shopify/Product/2001',
            'external_variant_id' => 'gid://shopify/ProductVariant/5001',
            'total_cents' => 2500,
        ]);

        $event = CommerceOrderEvent::factory()->actor($contact)->create([
            'commerce_order_id' => $order->id,
            'event' => CommerceOrderEvent::EVENT_PAID,
            'to_status' => CommerceOrder::STATUS_CLOSED,
        ]);

        $this->assertTrue($customer->contact->is($contact));
        $this->assertTrue($order->commerceCustomer->is($customer));
        $this->assertTrue($order->contact->is($contact));
        $this->assertTrue($order->items->contains($item));
        $this->assertTrue($order->events->contains($event));
        $this->assertTrue($item->commerceProduct->is($product));
        $this->assertTrue($event->actor->is($contact));
        $this->assertSame(CommerceOrder::FINANCIAL_STATUS_PAID, $order->financial_status);
        $this->assertSame('gid://shopify/Product/2001', $item->external_product_id);
    }

    public function test_commerce_foundation_does_not_create_storefront_tables(): void
    {
        $this->assertFalse(Schema::hasTable('commerce_carts'));
        $this->assertFalse(Schema::hasTable('commerce_checkouts'));
        $this->assertFalse(Schema::hasTable('commerce_payments'));
        $this->assertFalse(Schema::hasTable('commerce_fulfillments'));
        $this->assertFalse(Schema::hasTable('commerce_inventory_items'));
        $this->assertFalse(Schema::hasTable('commerce_product_variants'));
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function assertTableHasColumns(string $table, array $columns): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Missing column [{$table}.{$column}].",
            );
        }
    }
}
