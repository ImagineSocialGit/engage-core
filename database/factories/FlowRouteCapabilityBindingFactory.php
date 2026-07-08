<?php

namespace Database\Factories;

use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRouteCapabilityBinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowRouteCapabilityBinding>
 */
class FlowRouteCapabilityBindingFactory extends Factory
{
    protected $model = FlowRouteCapabilityBinding::class;

    public function definition(): array
    {
        return [
            'flow_route_capability_id' => FlowRouteCapability::factory(),
            'context_type' => null,
            'context_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'module_key' => null,
            'visibility' => FlowRouteCapability::VISIBILITY_OPERATOR,
            'sort_order' => 0,
            'label' => null,
            'description' => null,
            'help_text' => null,
            'defaults' => [],
            'constraints' => [],
            'input_overrides' => [],
            'output_overrides' => [],
            'is_enabled' => true,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'is_enabled' => false,
        ]);
    }
}
