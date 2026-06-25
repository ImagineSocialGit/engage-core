<?php

namespace Database\Factories;

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
            'related_type' => null,
            'related_id' => null,
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => TeamMember::factory(),
            'source' => Task::SOURCE_MANUAL,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => Task::STATUS_OPEN,
            'priority' => null,
            'due_at' => fake()->optional()->dateTimeBetween('now', '+14 days'),
            'completed_at' => null,
            'canceled_at' => null,
            'canceled_reason' => null,
            'archived_at' => null,
            'meta' => null,
        ];
    }

    public function assignedTo(Model $model): self
    {
        return $this->state([
            'assigned_to_type' => $model->getMorphClass(),
            'assigned_to_id' => $model->getKey(),
        ]);
    }

    public function relatedTo(?Model $model): self
    {
        return $this->state([
            'related_type' => $model?->getMorphClass(),
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
            'status' => Task::STATUS_COMPLETED,
            'completed_at' => now(),
            'canceled_at' => null,
            'canceled_reason' => null,
        ]);
    }

    public function canceled(?string $reason = null): self
    {
        return $this->state([
            'status' => Task::STATUS_CANCELED,
            'completed_at' => null,
            'canceled_at' => now(),
            'canceled_reason' => $reason,
        ]);
    }

    public function archived(): self
    {
        return $this->state([
            'archived_at' => now(),
        ]);
    }
}