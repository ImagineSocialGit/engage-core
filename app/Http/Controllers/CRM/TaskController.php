<?php

namespace App\Http\Controllers\CRM;

use App\Actions\CRM\Tasks\CreateManualTaskAction;
use App\Actions\CRM\Tasks\NotifyAssignedTaskRecipientsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\StoreTaskRequest;
use App\Models\Contact;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function store(
        StoreTaskRequest $request,
        CreateManualTaskAction $createManualTask,
        NotifyAssignedTaskRecipientsAction $notifyAssignedTaskRecipients,
    ): RedirectResponse {
        $task = $createManualTask->handle(
            data: $request->validated(),
        );

        if ($request->boolean('notify_assignee')) {
            $notifyAssignedTaskRecipients->handle($task);
        }

        return redirect()->back();
    }

    public function complete(Task $task): RedirectResponse
    {
        $task->update([
            'status' => Task::STATUS_COMPLETED,
            'completed_at' => now(),
            'canceled_at' => null,
            'canceled_reason' => null,
        ]);

        $this->touchRelatedContact($task);

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