<?php

namespace Database\Factories;

use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowRoutePoint>
 */
class FlowRoutePointFactory extends Factory
{
    protected $model = FlowRoutePoint::class;

    public function definition(): array
    {
        return [
            'flow_route_id' => FlowRoute::factory(),
            'flow_route_capability_id' => null,
            'key' => 'point-'.fake()->unique()->bothify('########'),
            'type' => FlowRoutePointType::Noop->value,
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->unique()->numberBetween(1, 10000),
            'is_start' => false,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ];
    }

    public function type(FlowRoutePointType|string $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type instanceof FlowRoutePointType ? $type->value : $type,
        ]);
    }

    public function start(): static
    {
        return $this->state(fn (): array => [
            'is_start' => true,
            'sort_order' => 0,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
