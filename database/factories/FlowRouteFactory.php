<?php

namespace Database\Factories;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowRoute>
 */
class FlowRouteFactory extends Factory
{
    protected $model = FlowRoute::class;

    public function definition(): array
    {
        return [
            'key' => 'flow-route-'.fake()->unique()->bothify('########'),
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => null,
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_MANUAL,
            'trigger_key' => null,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ];
    }

    public function forContactStatus(ContactStatus $contactStatus): static
    {
        return $this->state(fn (array $attributes): array => [
            'contact_status_id' => $contactStatus->getKey(),
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $contactStatus->key,
        ]);
    }

    public function forAutomationEvent(string $eventKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'contact_status_id' => null,
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
