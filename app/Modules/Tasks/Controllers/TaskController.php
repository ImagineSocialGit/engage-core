<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Actions\CompleteTaskAction;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Actions\NotifyAssignedTaskRecipientsAction;
use App\Modules\Tasks\Actions\RecordManualTaskAutomationBehaviorAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Requests\StoreTaskRequest;
use App\Modules\Tasks\Requests\UpdateTaskAssignmentRequest;
use Illuminate\Validation\ValidationException;
use App\Modules\Tasks\Services\TaskAssigneeOptionsResolver;
use App\Modules\Tasks\Services\TaskContactLinkResolver;
use App\Modules\Tasks\Services\TaskLinkPresentationResolver;
use App\Modules\Tasks\Services\TaskShowPresenter;
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
        Request $request,
        Task $task,
        TaskShowPresenter $showPresenter,
        TaskAssigneeOptionsResolver $assigneeOptions,
    ): View {
        $task->load([
            'assignedTo',
            'responsible',
            'taskTemplate',
            'links.linkable',
        ]);

        $options = $assigneeOptions->options($request->user());
        $taskContext = $showPresenter->present($task);

        return view('crm.tasks.show', [
            'title' => $task->title,
            'heading' => $task->title,
            'task' => $task,
            'taskContext' => $taskContext,
            'contactContext' => $taskContext['contact'],
            'inboundContext' => $taskContext['inbound'],
            'outboundContext' => $taskContext['outbound'],
            'origin' => $taskContext['origin'],
            'presentedLinks' => $taskContext['links'],
            'taskAssigneeOptions' => $options,
            'currentTaskAssigneeKey' => $options->first(fn ($option): bool =>
                $task->assignedTo
                && $option->assignee->getMorphClass() === $task->assignedTo->getMorphClass()
                && (string) $option->assignee->getKey() === (string) $task->assignedTo->getKey()
            )?->key(),
            'responsiblePartyLabels' => [
                Task::RESPONSIBLE_PARTY_INTERNAL => 'Internal team',
                Task::RESPONSIBLE_PARTY_CONTACT => str(config('contacts.labels.singular', 'Contact'))->title()->toString(),
                Task::RESPONSIBLE_PARTY_THIRD_PARTY => 'Third party',
                Task::RESPONSIBLE_PARTY_UNKNOWN => 'Unknown',
            ],
            'localDueAt' => $task->due_at?->timezone(config('client.timezone', config('app.timezone', 'UTC'))),
            'taskTone' => module_tone('tasks'),
        ]);
    }

    public function updateAssignment(
        UpdateTaskAssignmentRequest $request,
        Task $task,
        TaskAssigneeOptionsResolver $assigneeOptions,
        TaskContactLinkResolver $contactLinks,
    ): RedirectResponse {
        $key = $request->validated('assignee_key');
        $assignee = $key === null
            ? null
            : $assigneeOptions->options($request->user())->first(fn ($option): bool => $option->key() === $key)?->assignee;

        if ($key !== null && ! $assignee) {
            throw ValidationException::withMessages(['assignee_key' => 'The selected assignee is no longer available.']);
        }

        $meta = is_array($task->meta) ? $task->meta : [];
        $meta['assignment'] = [
            'assigned_to_type' => $assignee?->getMorphClass(),
            'assigned_to_id' => $assignee?->getKey(),
            'actor_user_id' => $request->user()?->getKey(),
            'assigned_at' => now()->toIso8601String(),
            'source' => 'crm_task_show',
        ];

        $task->forceFill([
            'assigned_to_type' => $assignee?->getMorphClass(),
            'assigned_to_id' => $assignee?->getKey(),
            'meta' => $meta,
        ])->save();

        $this->touchLinkedContact($task, $contactLinks);

        return back()->with('success', 'Task assignment updated.');
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