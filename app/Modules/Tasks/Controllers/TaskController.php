<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Actions\CompleteTaskAction;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Actions\NotifyAssignedTaskRecipientsAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Requests\StoreTaskRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function store(
        StoreTaskRequest $request,
        CreateTaskAction $createTask,
        NotifyAssignedTaskRecipientsAction $notifyAssignedTaskRecipients,
    ): RedirectResponse {
        $task = $createTask->handle(
            data: array_replace($request->validated(), [
                'source' => Task::SOURCE_MANUAL,
            ]),
        );

        if ($request->boolean('notify_assignee') && $task->isAssigned()) {
            $notifyAssignedTaskRecipients->handle($task);
        }

        return redirect()->back();
    }

    public function complete(Task $task, CompleteTaskAction $completeTask): RedirectResponse
    {
        $completeTask->handle($task);

        return redirect()->back();
    }

    public function cancel(Request $request, Task $task): RedirectResponse
    {
        $validated = $request->validate([
            'canceled_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $task->update([
            'status' => Task::STATUS_CANCELED,
            'completed_at' => null,
            'canceled_at' => now(),
            'canceled_reason' => $validated['canceled_reason'] ?? null,
        ]);

        $this->touchRelatedContact($task);

        return redirect()->back();
    }

    public function reopen(Task $task): RedirectResponse
    {
        $task->update([
            'status' => Task::STATUS_OPEN,
            'completed_at' => null,
            'canceled_at' => null,
            'canceled_reason' => null,
            'archived_at' => null,
        ]);

        $this->touchRelatedContact($task);

        return redirect()->back();
    }

    public function archive(Task $task): RedirectResponse
    {
        $task->update([
            'archived_at' => now(),
        ]);

        return redirect()->back();
    }

    public function restore(Task $task): RedirectResponse
    {
        $task->update([
            'archived_at' => null,
        ]);

        return redirect()->back();
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
