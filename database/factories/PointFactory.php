<?php

namespace Database\Factories;

use App\Modules\FlowRoutes\Models\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Point>
 */
class PointFactory extends Factory
{
    protected $model = Point::class;

    public function definition(): array
    {
        return [
            'key' => 'point-'.fake()->unique()->bothify('########'),
            'type' => Point::TYPE_NOOP,
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'default_definition' => [],
            'default_settings' => [],
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ];
    }

    public function type(string $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
