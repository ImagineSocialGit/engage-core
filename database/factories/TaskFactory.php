<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Task;
use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => TeamMember::factory(),
            'related_type' => Contact::class,
            'related_id' => Contact::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => 'open',
            'due_at' => fake()->optional()->dateTimeBetween('now', '+14 days'),
            'completed_at' => null,
        ];
    }

    public function assignedTo(Model $model): self
    {
        return $this->state([
            'assigned_to_type' => $model::class,
            'assigned_to_id' => $model->getKey(),
        ]);
    }

    public function relatedTo(?Model $model): self
    {
        return $this->state([
            'related_type' => $model ? $model::class : null,
            'related_id' => $model?->getKey(),
        ]);
    }

    public function unrelated(): self
    {
        return $this->relatedTo(null);
    }

    public function completed(): self
    {
        return $this->state([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}