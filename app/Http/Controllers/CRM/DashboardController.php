<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\DashboardAcknowledgement;
use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\Dashboard\TodayTasksDashboardPanelProvider;
use App\Modules\Tasks\Services\TaskLinkPresentationResolver;
use App\Support\Dashboard\DashboardPanelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const TASK_BROADCAST_MESSAGE_TYPE = 'dashboard_task_list';

    public function index(Request $request, DashboardPanelRegistry $dashboardPanels): View
    {
        $panelsBySlot = $dashboardPanels->panelsFor($request);
        $workPanels = $panelsBySlot->get('immediate_work', collect())->values();
        $contextPanels = $panelsBySlot->get('context', collect())->values();
        $allPanels = $workPanels->concat($contextPanels)->values();

        return view('crm.dashboard', [
            'title' => 'Dashboard',
            'heading' => 'Today',
            'subheading' => 'A clear place to start, without turning the CRM into a cockpit.',
            'summary' => $this->summary($allPanels),
            'primaryAction' => $this->primaryAction($workPanels),
            'rightNowCards' => $this->rightNowCards($workPanels, $contextPanels),
            'workPanels' => $workPanels,
            'contextPanels' => $contextPanels,
        ]);
    }

    public function printTasks(
        TodayTasksDashboardPanelProvider $tasksPanel,
        TaskLinkPresentationResolver $linkPresentation,
    ): View {
        $todayStart = $tasksPanel->todayStart();
        $todayEnd = $tasksPanel->todayEnd();
        $tasks = $tasksPanel->taskModels($todayEnd, 50);

        return view('crm.dashboard-tasks-print', [
            'title' => 'Today’s Task List',
            'printedAt' => now(config('client.timezone', config('app.timezone', 'UTC'))),
            'tasks' => $tasks,
            'taskItems' => $tasks
                ->map(fn (Task $task): array => $this->printableTaskItem(
                    $task,
                    $todayStart,
                    $todayEnd,
                    $linkPresentation,
                ))
                ->values(),
            'overdueCount' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at?->lt($todayStart) ?? false)
                ->count(),
            'dueTodayCount' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at?->betweenIncluded($todayStart, $todayEnd) ?? false)
                ->count(),
        ]);
    }

    public function broadcastTasks(
        ScheduleInternalNotificationAction $scheduleInternalNotification,
        TodayTasksDashboardPanelProvider $tasksPanel,
        TaskLinkPresentationResolver $linkPresentation,
    ): RedirectResponse {
        if (! $this->canBroadcastTaskList()) {
            return redirect()
                ->route('crm.index')
                ->with('error', 'Task sharing is available when Tasks, Team Members, Messaging, and Internal Notifications are enabled.');
        }

        $todayEnd = $tasksPanel->todayEnd();
        $tasks = $tasksPanel->taskModels($todayEnd, 50);

        if ($tasks->isEmpty()) {
            return redirect()
                ->route('crm.index')
                ->with('error', 'There are no tasks for today to share right now.');
        }

        $teamMembers = TeamMember::query()
            ->active()
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('email')
                    ->orWhereNotNull('phone');
            })
            ->orderBy('name')
            ->get();

        if ($teamMembers->isEmpty()) {
            return redirect()
                ->route('crm.index')
                ->with('error', 'Add an active team member with an email address before sharing the task list.');
        }

        $scheduled = 0;

        foreach ($teamMembers as $teamMember) {
            $recipient = new InternalNotificationRecipient(
                source: $teamMember,
                name: $teamMember->name,
                email: $teamMember->email,
                phone: $teamMember->phone,
                notificationType: self::TASK_BROADCAST_MESSAGE_TYPE,
                preferenceOwner: $teamMember,
            );

            $message = $scheduleInternalNotification->handle(
                recipient: $recipient,
                scope: 'tasks',
                messageType: self::TASK_BROADCAST_MESSAGE_TYPE,
                content: $this->taskBroadcastContent($tasks, $teamMember, $linkPresentation),
                context: null,
                dedupeKey: $this->taskBroadcastDedupeKey($teamMember),
                meta: [
                    'surface' => 'crm_dashboard',
                    'task_count' => $tasks->count(),
                    'task_ids' => $tasks->pluck('id')->values()->all(),
                ],
                allowedChannels: [MessageChannel::Email, MessageChannel::Sms],
            );

            if ($message !== null) {
                $scheduled++;
            }
        }

        if ($scheduled === 0) {
            return redirect()
                ->route('crm.index')
                ->with('error', 'No team members are currently eligible for task-list notifications.');
        }

        return redirect()
            ->route('crm.index')
            ->with('success', 'Today’s task list was shared with '.number_format($scheduled).' '.Str::plural('team member', $scheduled).'.');
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'item_type' => ['required', 'string', 'max:80'],
            'item_key' => ['required', 'string', 'max:191'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        DashboardAcknowledgement::query()->updateOrCreate(
            [
                'user_id' => $request->user()?->id,
                'surface' => DashboardAcknowledgement::SURFACE_CRM_DASHBOARD,
                'item_type' => DashboardAcknowledgement::normalizeItemType($validated['item_type']),
                'item_key' => DashboardAcknowledgement::normalizeItemKey($validated['item_key']),
            ],
            [
                'acknowledged_at' => now(),
                'expires_at' => null,
            ],
        );

        return redirect($this->safeReturnTo($validated['return_to'] ?? null))
            ->with('success', 'Cleared from today’s dashboard.');
    }

    /**
     * @param Collection<int, array<string, mixed>> $panels
     * @return array<string, mixed>
     */
    private function summary(Collection $panels): array
    {
        $panelCounts = $panels
            ->mapWithKeys(fn (array $panel): array => [
                (string) $panel['key'] => [
                    'count' => (int) ($panel['count'] ?? 0),
                    'attention_count' => (int) ($panel['attention_count'] ?? 0),
                ],
            ])
            ->all();

        return [
            'attention_count' => $panels
                ->sum(fn (array $panel): int => (int) ($panel['attention_count'] ?? 0)),
            'panels' => $panelCounts,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $workPanels
     * @return array<string, mixed>|null
     */
    private function primaryAction(Collection $workPanels): ?array
    {
        return $workPanels
            ->map(fn (array $panel): ?array => $panel['primary_action'] ?? null)
            ->filter(fn (?array $action): bool =>
                filled($action['href'] ?? null)
                || filled($action['summary'] ?? null)
            )
            ->first() ?: [
                'label' => 'View '.config('contacts.labels.plural'),
                'href' => route('crm.contacts.index'),
                'summary' => 'No urgent item is waiting. Review '.config('contacts.labels.plural').' when you are ready.',
            ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $workPanels
     * @param Collection<int, array<string, mixed>> $contextPanels
     * @return array<int, array<string, mixed>>
     */
    private function rightNowCards(
        Collection $workPanels,
        Collection $contextPanels,
    ): array {
        $firstWorkPanel = $workPanels->first();

        $cards = [[
            'label' => 'need attention',
            'count' => $workPanels
                ->sum(fn (array $panel): int => (int) ($panel['attention_count'] ?? 0)),
            'module' => is_array($firstWorkPanel)
                ? ($firstWorkPanel['module'] ?? 'core')
                : 'core',
            'target_ref' => is_array($firstWorkPanel)
                ? ($firstWorkPanel['target_ref'] ?? null)
                : null,
        ]];

        foreach ($workPanels->concat($contextPanels)->take(3) as $panel) {
            $cards[] = [
                'label' => $panel['summary_label'] ?? $panel['title'],
                'count' => (int) ($panel['count'] ?? 0),
                'module' => $panel['module'] ?? 'core',
                'target_ref' => $panel['target_ref'] ?? null,
            ];
        }

        return array_slice($cards, 0, 4);
    }

    /**
     * @return array<string, mixed>
     */
    private function printableTaskItem(
        Task $task,
        mixed $todayStart,
        mixed $todayEnd,
        TaskLinkPresentationResolver $linkPresentation,
    ): array {
        $primaryLink = $linkPresentation->primary($task);
        $dueAt = $task->due_at;
        $isOverdue = $dueAt && $dueAt->lt($todayStart);
        $isDueToday = $dueAt && $dueAt->betweenIncluded($todayStart, $todayEnd);

        return [
            'label' => $isOverdue ? 'Overdue' : ($isDueToday ? 'Today' : 'Open'),
            'title' => $task->title,
            'subtitle' => trim(implode(' · ', array_filter([
                $primaryLink['name'] ?? null,
                $this->dueLabel($dueAt),
                $task->assignedTo
                    ? 'Owner: '.$this->modelName($task->assignedTo)
                    : 'Unassigned',
            ]))),
            'description' => $task->description,
        ];
    }

    /**
     * @param Collection<int, Task> $tasks
     * @return array<string, mixed>
     */
    private function taskBroadcastContent(
        Collection $tasks,
        TeamMember $teamMember,
        TaskLinkPresentationResolver $linkPresentation,
    ): array {
        $count = $tasks->count();
        $lines = $tasks
            ->take(12)
            ->map(fn (Task $task): string => $this->taskBroadcastLine($task, $linkPresentation))
            ->when(
                $count > 12,
                fn (Collection $lines): Collection =>
                    $lines->push('And '.($count - 12).' more.')
            )
            ->values()
            ->all();

        return [
            'subject' => 'Today’s task list: '.$count.' open '.Str::plural('task', $count),
            'headline' => 'Today’s task list',
            'preheader' => $count.' open '.Str::plural('task', $count).' need attention today.',
            'body' => [
                'Here is the current task list from the CRM dashboard.',
                ...$lines,
            ],
            'details' => [
                'Recipient' => $teamMember->name,
                'Open Tasks' => (string) $count,
                'Dashboard' => route('crm.index'),
            ],
            'cta' => [
                'label' => 'Open dashboard',
                'url' => route('crm.index'),
            ],
            'sms_message' => 'Today’s task list: '.$count.' open '.Str::plural('task', $count).'. Open the CRM dashboard for details.',
            'meta' => [
                'surface' => 'crm_dashboard',
                'task_count' => $count,
                'task_ids' => $tasks->pluck('id')->values()->all(),
            ],
        ];
    }

    private function taskBroadcastLine(
        Task $task,
        TaskLinkPresentationResolver $linkPresentation,
    ): string {
        $primaryLink = $linkPresentation->primary($task);
        $segments = [$task->title];

        if ($primaryLink) {
            $segments[] = $primaryLink['name'];
        }

        $segments[] = $task->due_at
            ? $this->dueLabel($task->due_at)
            : 'No due date';

        if ($task->assignedTo) {
            $segments[] = 'Owner: '.$this->modelName($task->assignedTo);
        }

        return '• '.implode(' — ', array_filter($segments));
    }

    private function taskBroadcastDedupeKey(TeamMember $teamMember): string
    {
        return implode(':', [
            'internal_notification',
            'crm_dashboard',
            self::TASK_BROADCAST_MESSAGE_TYPE,
            now(config('client.timezone', config('app.timezone', 'UTC')))->toDateString(),
            $teamMember->getMorphClass(),
            $teamMember->getKey(),
        ]);
    }

    private function canBroadcastTaskList(): bool
    {
        return module_enabled('tasks')
            && module_enabled('messaging')
            && module_enabled('internal_notifications');
    }

    private function safeReturnTo(?string $returnTo): string
    {
        $fallback = route('crm.index');

        if (! is_string($returnTo)
            || trim($returnTo) === ''
            || preg_match('/[\x00-\x1F\x7F]/', $returnTo) === 1
        ) {
            return $fallback;
        }

        $returnTo = trim($returnTo);

        if (! str_starts_with($returnTo, '/')
            || str_starts_with($returnTo, '//')
            || str_contains($returnTo, '\\')
        ) {
            return $fallback;
        }

        $decoded = $returnTo;
        $fullyDecoded = false;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
                || str_contains($decoded, '\\')
                || str_starts_with($decoded, '//')
            ) {
                return $fallback;
            }

            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                $fullyDecoded = true;

                break;
            }

            $decoded = $next;
        }

        if (! $fullyDecoded
            || ! str_starts_with($decoded, '/')
            || str_starts_with($decoded, '//')
            || str_contains($decoded, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
        ) {
            return $fallback;
        }

        return $returnTo;
    }

    private function dueLabel(mixed $date): ?string
    {
        if (! $date) {
            return 'No due date';
        }

        $localDate = $date->copy()->timezone(
            config('client.timezone', config('app.timezone', 'UTC'))
        );

        $today = now(
            config('client.timezone', config('app.timezone', 'UTC'))
        );

        if ($localDate->isBefore($today->copy()->startOfDay())) {
            return 'Due '.$localDate->format('M j, g:i A');
        }

        if ($localDate->isSameDay($today)) {
            return 'Due today '.$localDate->format('g:i A');
        }

        return 'Due '.$localDate->format('M j, g:i A');
    }

    private function modelName(mixed $model): string
    {
        foreach (['name', 'email', 'title'] as $attribute) {
            $value = $model->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }
}
