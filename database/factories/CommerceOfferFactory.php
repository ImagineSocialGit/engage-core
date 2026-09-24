<?php

namespace Database\Factories;

use App\Modules\Commerce\Models\CommerceOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommerceOffer>
 */
class CommerceOfferFactory extends Factory
{
    protected $model = CommerceOffer::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'slug' => fake()->unique()->slug(3),
            'title' => fake()->words(3, true),
            'description' => null,
            'status' => CommerceOffer::STATUS_ACTIVE,
            'provider_scope' => null,
            'position' => 0,
            'publish_starts_at' => null,
            'publish_ends_at' => null,
            'meta' => null,
        ];
    }

    public function active(): self
    {
        return $this->state([
            'status' => CommerceOffer::STATUS_ACTIVE,
        ]);
    }
}