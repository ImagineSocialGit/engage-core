<?php

namespace Database\Factories;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    protected $model = TaskTemplate::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(3),
            'group_key' => 'general_default',
            'name' => fake()->sentence(3),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'task_description' => fake()->optional()->paragraph(),
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'priority' => null,
            'due_offset_days' => fake()->optional()->numberBetween(1, 14),
            'is_active' => true,
            'meta' => null,
        ];
    }

    public function group(string $groupKey): self
    {
        return $this->state([
            'group_key' => $groupKey,
        ]);
    }

    public function contactResponsible(): self
    {
        return $this->state([
            'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
        ]);
    }

    public function thirdPartyResponsible(): self
    {
        return $this->state([
            'responsible_party' => Task::RESPONSIBLE_PARTY_THIRD_PARTY,
        ]);
    }

    public function inactive(): self
    {
        return $this->state([
            'is_active' => false,
        ]);
    }
}