<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<TaskLink>
 */
class TaskLinkFactory extends Factory
{
    protected $model = TaskLink::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'linkable_type' => Contact::class,
            'linkable_id' => Contact::factory(),
            'role' => TaskLink::ROLE_SUBJECT,
        ];
    }

    public function forLinkable(
        Model $model,
        string $role = TaskLink::ROLE_SUBJECT,
    ): self {
        return $this->state([
            'linkable_type' => $model->getMorphClass(),
            'linkable_id' => $model->getKey(),
            'role' => $role,
        ]);
    }

    public function context(): self
    {
        return $this->state([
            'role' => TaskLink::ROLE_CONTEXT,
        ]);
    }

    public function result(): self
    {
        return $this->state([
            'role' => TaskLink::ROLE_RESULT,
        ]);
    }
}
