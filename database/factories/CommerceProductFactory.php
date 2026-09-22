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
            'key' => fake()->unique()->slug(3),
            'sku' => fake()->optional()->bothify('PROD-####'),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'status' => CommerceProduct::STATUS_ACTIVE,
            'product_type' => null,
            'vendor' => null,
            'category' => null,
            'tags' => [],
            'currency' => 'USD',
            'price_cents' => 2500,
            'published_at' => now()->subMonth(),
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
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