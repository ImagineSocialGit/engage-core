<?php

namespace Database\Factories;

use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowRouteCapability>
 */
class FlowRouteCapabilityFactory extends Factory
{
    protected $model = FlowRouteCapability::class;

    public function definition(): array
    {
        return [
            'key' => 'tasks.create_task',
            'module_key' => 'tasks',
            'capability_type' => FlowRouteCapability::TYPE_ACTION,
            'point_type' => Point::TYPE_CREATE_TASK,
            'handler_key' => Point::TYPE_CREATE_TASK,
            'event_key' => null,
            'action_key' => 'create_task',
            'name' => 'Create task',
            'description' => null,
            'category' => 'tasks',
            'surface' => 'route_management',
            'supported_subjects' => [],
            'required_modules' => ['tasks'],
            'input_schema' => [],
            'output_schema' => [],
            'available_fields' => [],
            'defaults' => [],
            'is_active' => true,
            'source' => 'preset',
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ];
    }

    public function forModule(string $moduleKey): static
    {
        return $this->state(fn (): array => [
            'module_key' => $moduleKey,
        ]);
    }

    public function pointType(string $pointType): static
    {
        return $this->state(fn (): array => [
            'point_type' => $pointType,
            'handler_key' => $pointType,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
