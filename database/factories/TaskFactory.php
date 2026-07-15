<?php

namespace Database\Factories;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'responsible_type' => null,
            'responsible_id' => null,
            'task_template_id' => null,
            'task_template_key' => null,
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

    public function linkedTo(
        Model $model,
        string $role = TaskLink::ROLE_SUBJECT,
    ): self {
        if (! in_array($role, TaskLink::ROLES, true)) {
            throw new InvalidArgumentException("Invalid TaskLink role [{$role}].");
        }

        return $this->afterCreating(function (Task $task) use ($model, $role): void {
            $task->links()->firstOrCreate([
                'linkable_type' => $model->getMorphClass(),
                'linkable_id' => $model->getKey(),
                'role' => $role,
            ]);
        });
    }

    public function unlinked(): self
    {
        return $this;
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
