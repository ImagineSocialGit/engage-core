<?php

namespace Database\Factories;

use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Models\CommerceProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommerceOrderItem>
 */
class CommerceOrderItemFactory extends Factory
{
    protected $model = CommerceOrderItem::class;

    public function definition(): array
    {
        return [
            'commerce_order_id' => CommerceOrder::factory(),
            'commerce_product_id' => CommerceProduct::factory(),
            'item_type' => CommerceOrderItem::TYPE_PRODUCT,
            'sku' => 'TSHIRT-CLASSIC-M',
            'name' => 'Classic T-shirt',
            'title' => 'Classic T-shirt',
            'variant_title' => 'Medium',
            'options' => ['Size' => 'Medium'],
            'quantity' => 1,
            'currency' => 'USD',
            'unit_price_cents' => 2500,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 2500,
            'fulfillment_status' => CommerceOrder::FULFILLMENT_STATUS_FULFILLED,
            'source' => 'provider',
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/LineItem/4001',
            'external_product_id' => 'gid://shopify/Product/2001',
            'external_variant_id' => 'gid://shopify/ProductVariant/5001',
            'external_url' => null,
            'raw_payload' => null,
            'meta' => null,
        ];
    }
}
