<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Actions\CompleteTaskAction;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Actions\NotifyAssignedTaskRecipientsAction;
use App\Modules\Tasks\Actions\RecordManualTaskAutomationBehaviorAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Requests\StoreTaskRequest;
use App\Modules\Tasks\Services\TaskAssigneeOptionsResolver;
use App\Modules\Tasks\Services\TaskContactLinkResolver;
use App\Modules\Tasks\Services\TaskLinkPresentationResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(
        Request $request,
        TaskAssigneeOptionsResolver $assigneeOptions,
        TaskLinkPresentationResolver $linkPresentation,
    ): View {
        $taskView = $request->query('task_view') === 'archived'
            ? 'archived'
            : 'active';

        $status = $this->statusFilter($request->query('status'));
        $search = trim((string) $request->query('search', ''));

        $query = Task::query()
            ->with([
                'assignedTo',
                'responsible',
                'taskTemplate',
                'links.linkable',
            ])
            ->when(
                $taskView === 'archived',
                fn (Builder $query): Builder => $query->archived(),
                fn (Builder $query): Builder => $query->unarchived(),
            )
            ->when(
                $status !== null,
                fn (Builder $query): Builder => $query->where('status', $status),
            )
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                }),
            )
            ->orderByRaw(
                'CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END',
                [Task::STATUS_OPEN, Task::STATUS_COMPLETED],
            )
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->latest('id');

        $tasks = $query
            ->paginate(30)
            ->withQueryString();

        $presentedLinks = $tasks->getCollection()
            ->mapWithKeys(fn (Task $task): array => [
                $task->getKey() => $linkPresentation->forTask($task),
            ]);

        $options = $assigneeOptions->options($request->user());

        return view('crm.tasks.index', [
            'title' => 'Tasks',
            'heading' => 'Tasks',
            'taskView' => $taskView,
            'statusFilter' => $status,
            'search' => $search,
            'tasks' => $tasks,
            'presentedLinks' => $presentedLinks,
            'counts' => [
                'open' => Task::query()->unarchived()->open()->count(),
                'completed' => Task::query()->unarchived()->completed()->count(),
                'canceled' => Task::query()->unarchived()->canceled()->count(),
                'archived' => Task::query()->archived()->count(),
            ],
            'taskAssigneeOptions' => $options,
            'currentTaskAssigneeKey' => $options
                ->first(fn ($option): bool => $option->isCurrent)
                ?->key(),
            'defaultTaskDueAt' => now(config('client.timezone', config('app.timezone', 'UTC')))
                ->addDay()
                ->setTime(9, 0)
                ->format('Y-m-d\TH:i'),
        ]);
    }

    public function show(
        Task $task,
        TaskLinkPresentationResolver $linkPresentation,
    ): View {
        $task->load([
            'assignedTo',
            'responsible',
            'taskTemplate',
            'links.linkable',
        ]);

        return view('crm.tasks.show', [
            'title' => $task->title,
            'heading' => $task->title,
            'task' => $task,
            'presentedLinks' => $linkPresentation->forTask($task),
        ]);
    }

    public function store(
        StoreTaskRequest $request,
        CreateTaskAction $createTask,
        RecordManualTaskAutomationBehaviorAction $recordManualTaskAutomationBehavior,
        NotifyAssignedTaskRecipientsAction $notifyAssignedTaskRecipients,
    ): RedirectResponse {
        $task = $createTask->handle(
            data: array_replace($request->validated(), [
                'source' => Task::SOURCE_MANUAL,
            ]),
        );

        $recordManualTaskAutomationBehavior->handle(
            task: $task,
            actor: $request->user(),
        );

        if ($request->boolean('notify_assignee') && $task->isAssigned()) {
            $notifyAssignedTaskRecipients->handle($task);
        }

        return redirect()
            ->back()
            ->with('success', 'Task created.');
    }

    public function complete(
        Request $request,
        Task $task,
        CompleteTaskAction $completeTask,
    ): RedirectResponse {
        $completeTask->handle(
            task: $task,
            actor: $request->user(),
            source: 'crm',
            meta: [
                'source' => 'task_controller.complete',
            ],
        );

        return redirect()
            ->back()
            ->with('success', 'Task completed.');
    }

    public function cancel(
        Request $request,
        Task $task,
        TaskContactLinkResolver $contactLinks,
    ): RedirectResponse {
        $validated = $request->validate([
            'canceled_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $task->update([
            'status' => Task::STATUS_CANCELED,
            'completed_at' => null,
            'canceled_at' => now(),
            'canceled_reason' => $validated['canceled_reason'] ?? null,
        ]);

        $this->touchLinkedContact($task, $contactLinks);

        return redirect()
            ->back()
            ->with('success', 'Task canceled.');
    }

    public function reopen(
        Task $task,
        TaskContactLinkResolver $contactLinks,
    ): RedirectResponse {
        $task->update([
            'status' => Task::STATUS_OPEN,
            'completed_at' => null,
            'canceled_at' => null,
            'canceled_reason' => null,
            'archived_at' => null,
        ]);

        $this->touchLinkedContact($task, $contactLinks);

        return redirect()
            ->back()
            ->with('success', 'Task reopened.');
    }

    public function archive(Task $task): RedirectResponse
    {
        $task->update([
            'archived_at' => now(),
        ]);

        return redirect()
            ->back()
            ->with('success', 'Task archived.');
    }

    public function restore(Task $task): RedirectResponse
    {
        $task->update([
            'archived_at' => null,
        ]);

        return redirect()
            ->back()
            ->with('success', 'Task restored.');
    }

    private function touchLinkedContact(
        Task $task,
        TaskContactLinkResolver $contactLinks,
    ): void {
        $contact = $contactLinks->resolve($task);

        if (! $contact) {
            return;
        }

        $contact->forceFill([
            'last_activity_at' => now(),
        ])->save();
    }

    private function statusFilter(mixed $value): ?string
    {
        return is_string($value) && in_array($value, [
            Task::STATUS_OPEN,
            Task::STATUS_COMPLETED,
            Task::STATUS_CANCELED,
        ], true)
            ? $value
            : null;
    }
}
