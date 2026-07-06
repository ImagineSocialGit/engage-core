<?php

namespace Database\Factories;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowRouteTriggerBinding>
 */
class FlowRouteTriggerBindingFactory extends Factory
{
    protected $model = FlowRouteTriggerBinding::class;

    public function definition(): array
    {
        return [
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'test.event',
            'flow_route_id' => FlowRoute::factory(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ];
    }

    public function forContactStatus(string $statusKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $statusKey,
        ]);
    }

    public function forAutomationEvent(string $eventKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => $eventKey,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
