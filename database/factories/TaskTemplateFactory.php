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
            'source' => TaskTemplate::SOURCE_PRESET,
            'source_version' => 'test',
            'owner_group' => null,
            'category' => null,
            'name' => fake()->sentence(3),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'task_description' => fake()->optional()->paragraph(),
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'assigned_to_strategy' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'responsible_type' => null,
            'responsible_id' => null,
            'priority' => null,
            'due_offset_minutes' => fake()->optional()->numberBetween(60, 20160),
            'related_subject' => null,
            'defaults' => null,
            'is_active' => true,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => null,
        ];
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

    public function assignedToOnlyActiveTeamMember(): self
    {
        return $this->state([
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_ONLY_ACTIVE_TEAM_MEMBER,
        ]);
    }

    public function inactive(): self
    {
        return $this->state([
            'is_active' => false,
        ]);
    }

    public function customized(): self
    {
        return $this->state([
            'is_customized' => true,
            'customized_at' => now(),
        ]);
    }
}
