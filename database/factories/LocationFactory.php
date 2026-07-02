<?php

namespace Database\Factories;

use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'key' => null,
            'name' => 'Example Location',
            'label' => 'Home',
            'type' => Location::TYPE_ADDRESS,
            'status' => Location::STATUS_ACTIVE,
            'address_line_1' => '123 Main Street',
            'address_line_2' => null,
            'city' => 'Melbourne',
            'region' => 'FL',
            'postal_code' => '32901',
            'country' => 'US',
            'formatted_address' => '123 Main Street, Melbourne, FL 32901',
            'latitude' => 28.0836,
            'longitude' => -80.6081,
            'timezone' => 'America/New_York',
            'precision' => 'address',
            'confidence' => 1.0000,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'geocoded_at' => null,
            'raw_payload' => null,
            'meta' => null,
        ];
    }

    public function geocoded(): self
    {
        return $this->state(['geocoded_at' => now()]);
    }
}
