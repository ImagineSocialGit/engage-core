<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;

class CompleteTaskAction
{
    public function handle(Task $task): Task
    {
        $completedTask = DB::transaction(function () use ($task): array {
            $task = Task::query()
                ->lockForUpdate()
                ->findOrFail($task->getKey());

            $wasCompleted = $task->isCompleted();

            $task->forceFill([
                'status' => Task::STATUS_COMPLETED,
                'completed_at' => $task->completed_at ?? now(),
                'canceled_at' => null,
                'canceled_reason' => null,
            ])->save();

            $task->refresh();

            $this->touchRelatedContact($task);

            return [$task, $wasCompleted];
        });

        /** @var Task $task */
        [$task, $wasCompleted] = $completedTask;

        if (! $wasCompleted) {
            event(new TaskCompleted($task));
        }

        return $task->refresh();
    }

    private function touchRelatedContact(Task $task): void
    {
        if (! $task->related_type || ! $task->related_id) {
            return;
        }

        $contactMorphClass = (new Contact())->getMorphClass();

        if (! in_array($task->related_type, [Contact::class, $contactMorphClass], true)) {
            return;
        }

        Contact::query()
            ->whereKey($task->related_id)
            ->update([
                'last_activity_at' => now(),
            ]);
    }
}
