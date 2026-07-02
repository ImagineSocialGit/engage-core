<?php

namespace Database\Factories;

use App\Modules\Scheduling\Models\BookableService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookableService>
 */
class BookableServiceFactory extends Factory
{
    protected $model = BookableService::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'key' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->optional()->paragraph(),
            'status' => BookableService::STATUS_ACTIVE,
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'location_type' => null,
            'location_details' => null,
            'capacity' => 1,
            'requires_confirmation' => false,
            'is_public' => true,
            'sort_order' => 0,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'meta' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state([
            'status' => BookableService::STATUS_INACTIVE,
            'is_public' => false,
        ]);
    }
}
