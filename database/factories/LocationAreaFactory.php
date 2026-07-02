<?php

namespace Database\Factories;

use App\Modules\Location\Models\LocationArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationArea>
 */
class LocationAreaFactory extends Factory
{
    protected $model = LocationArea::class;

    public function definition(): array
    {
        return [
            'key' => 'space_coast',
            'name' => 'Space Coast',
            'description' => 'Example regional service area.',
            'type' => LocationArea::TYPE_SERVICE_AREA,
            'status' => LocationArea::STATUS_ACTIVE,
            'boundary_type' => LocationArea::BOUNDARY_TYPE_RADIUS,
            'country' => 'US',
            'region' => 'FL',
            'city' => null,
            'postal_code' => null,
            'center_latitude' => 28.0836,
            'center_longitude' => -80.6081,
            'radius_meters' => 50000,
            'geometry' => null,
            'timezone' => 'America/New_York',
            'is_service_area' => true,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'settings' => [],
            'meta' => null,
        ];
    }
}
