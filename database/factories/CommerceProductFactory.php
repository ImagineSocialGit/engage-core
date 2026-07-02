<?php

namespace Database\Factories;

use App\Modules\Commerce\Models\CommerceProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommerceProduct>
 */
class CommerceProductFactory extends Factory
{
    protected $model = CommerceProduct::class;

    public function definition(): array
    {
        return [
            'key' => 'classic-t-shirt',
            'sku' => 'TSHIRT-CLASSIC',
            'name' => 'Classic T-shirt',
            'description' => 'A synced commerce product snapshot.',
            'status' => CommerceProduct::STATUS_ACTIVE,
            'product_type' => 'Apparel',
            'vendor' => 'Example Store',
            'category' => 'shirts',
            'tags' => ['t-shirt', 'apparel'],
            'currency' => 'USD',
            'price_cents' => 2500,
            'published_at' => now()->subMonth(),
            'source' => 'provider',
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Product/2001',
            'external_url' => null,
            'raw_payload' => null,
            'meta' => null,
        ];
    }

    public function active(): self
    {
        return $this->state(['status' => CommerceProduct::STATUS_ACTIVE]);
    }
}
