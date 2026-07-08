<?php

namespace Database\Factories;

use App\Modules\Webinars\Models\WebinarScheduleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebinarScheduleProfileFactory extends Factory
{
    protected $model = WebinarScheduleProfile::class;

    public function definition(): array
    {
        return [
            'key' => 'test_profile_'.$this->faker->unique()->numberBetween(1000, 9999),
            'name' => 'Test Schedule Profile',
            'description' => null,
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => false,
            'is_active' => true,
            'source' => 'factory',
            'source_config_path' => null,
            'source_version' => null,
            'last_synced_at' => now(),
            'meta' => [],
        ];
    }
}
