<?php

namespace Database\Factories;

use App\Modules\Commerce\Models\CommerceOffer;
use App\Modules\Commerce\Models\CommerceOfferVariant;
use App\Modules\Commerce\Models\CommerceProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommerceOfferVariant>
 */
class CommerceOfferVariantFactory extends Factory
{
    protected $model = CommerceOfferVariant::class;

    public function definition(): array
    {
        return [
            'commerce_offer_id' => CommerceOffer::factory(),
            'commerce_product_variant_id' => CommerceProductVariant::factory(),
            'status' => CommerceOfferVariant::STATUS_ACTIVE,
            'is_default' => false,
            'position' => 0,
            'meta' => null,
        ];
    }

    public function default(): self
    {
        return $this->state([
            'is_default' => true,
        ]);
    }
}