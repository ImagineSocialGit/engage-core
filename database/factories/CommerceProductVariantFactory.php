<?php

namespace Database\Factories;

use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommerceProductVariant>
 */
class CommerceProductVariantFactory extends Factory
{
    protected $model = CommerceProductVariant::class;

    public function definition(): array
    {
        return [
            'commerce_product_id' => CommerceProduct::factory(),
            'key' => fake()->unique()->slug(2),
            'sku' => fake()->optional()->bothify('SKU-####'),
            'barcode' => null,
            'title' => 'Default',
            'status' => CommerceProductVariant::STATUS_ACTIVE,
            'options' => [],
            'position' => 0,
            'meta' => null,
        ];
    }

    public function active(): self
    {
        return $this->state([
            'status' => CommerceProductVariant::STATUS_ACTIVE,
        ]);
    }
}