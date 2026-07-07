<?php

namespace App\Modules\Tasks\Services\Dashboard;

use App\Models\DashboardAcknowledgement;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TodayTasksDashboardPanelProvider implements DashboardPanelProvider
{
    public const UPCOMING_TASKS_ITEM_TYPE = 'upcoming_tasks_week';

    public function key(): string
    {
        return 'tasks.today';
    }

    public function module(): string
    {
        return 'tasks';
    }

    /**
     * @return array<string, mixed>
     */
    public function panel(Request $request): array
    {
        $todayStart = $this->todayStart();
        $todayEnd = $this->todayEnd();
        $baseQuery = $this->baseTaskQuery();

        $overdueCount = (clone $baseQuery)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $todayStart)
            ->count();

        $dueTodayCount = (clone $baseQuery)
            ->whereBetween('due_at', [$todayStart, $todayEnd])
            ->count();

        $todayTasks = $this->taskModels($todayEnd, 10);
        $upcomingWeekCount = $this->upcomingTaskCount($todayEnd);
        $upcomingSummaryKey = $this->upcomingTaskSummaryKey();

        if ($upcomingWeekCount > 0 && $this->isDashboardItemAcknowledged($request, self::UPCOMING_TASKS_ITEM_TYPE, $upcomingSummaryKey)) {
            $upcomingWeekCount = 0;
        }

        return [
            'key' => $this->key(),
            'module' => $this->module(),
            'slot' => 'immediate_work',
            'priority' => $overdueCount > 0 ? 120 : 100,
            'order' => 10,
            'view' => 'tasks_today',
            'title' => 'Today’s tasks',
            'description' => 'Overdue, due-today, and undated open tasks. Future dated tasks stay out of this list.',
            'empty_title' => 'No tasks need your attention today.',
            'empty_description' => 'The manual follow-up list is clear.',
            'summary_label' => 'tasks due/overdue',
            'count' => $overdueCount + $dueTodayCount,
            'attention_count' => $overdueCount + $dueTodayCount,
            'overdue_count' => $overdueCount,
            'due_today_count' => $dueTodayCount,
            'items' => $todayTasks->map(fn (Task $task): array => $this->taskItem($task, $todayStart, $todayEnd))->values(),
            'upcoming_summary' => $upcomingWeekCount > 0 ? [
                'type' => self::UPCOMING_TASKS_ITEM_TYPE,
                'key' => $upcomingSummaryKey,
                'count' => $upcomingWeekCount,
            ] : null,
            'can_broadcast' => $this->canBroadcastTaskList(),
            'primary_action' => $this->primaryAction($todayTasks, $todayStart, $todayEnd),
        ];
    }

    /**
     * @return Collection<int, Task>
     */
    public function taskModels(Carbon $todayEnd, int $limit): Collection
    {
        return $this->baseTaskQuery()
            ->where(function (Builder $query) use ($todayEnd): void {
                $query
                    ->whereNull('due_at')
                    ->orWhere('due_at', '<=', $todayEnd);
            })
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function todayStart(): Carbon
    {
        return now(config('client.timezone', config('app.timezone', 'UTC')))
            ->startOfDay()
            ->utc();
    }

    public function todayEnd(): Carbon
    {
        return now(config('client.timezone', config('app.timezone', 'UTC')))
            ->endOfDay()
            ->utc();
    }

    /**
     * @param Collection<int, Task> $tasks
     * @return array<string, mixed>|null
     */
    private function primaryAction(Collection $tasks, Carbon $todayStart, Carbon $todayEnd): ?array
    {
        $task = $tasks->first(function (Task $task) use ($todayStart): bool {
            return $task->due_at?->lt($todayStart) ?? false;
        }) ?: $tasks->first(function (Task $task) use ($todayStart, $todayEnd): bool {
            return $task->due_at?->betweenIncluded($todayStart, $todayEnd) ?? false;
        });

        if (! $task) {
            return null;
        }

        $relatedContact = $task->related instanceof Contact ? $task->related : null;

        return [
            'label' => $task->due_at?->lt($todayStart) ? 'Open overdue task' : 'Open today’s task',
            'href' => $relatedContact ? route('crm.contacts.show', $relatedContact).'?activity_tab=tasks' : null,
            'summary' => $task->due_at?->lt($todayStart)
                ? 'Start with the overdue task at the top of today’s list.'
                : 'Start with the first task due today.',
        ];
    }

    private function upcomingTaskCount(Carbon $todayEnd): int
    {
        return $this->baseTaskQuery()
            ->whereNotNull('due_at')
            ->where('due_at', '>', $todayEnd)
            ->where('due_at', '<=', $this->weekEnd())
            ->count();
    }

    private function baseTaskQuery(): Builder
    {
        return Task::query()
            ->with(['assignedTo', 'related'])
            ->open()
            ->unarchived();
    }

    /**
     * @return array<string, mixed>
     */
    private function taskItem(Task $task, Carbon $todayStart, Carbon $todayEnd): array
    {
        $relatedContact = $task->related instanceof Contact ? $task->related : null;
        $dueAt = $task->due_at;
        $isOverdue = $dueAt && $dueAt->lt($todayStart);
        $isDueToday = $dueAt && $dueAt->betweenIncluded($todayStart, $todayEnd);

        return [
            'key' => (string) $task->id,
            'type' => DashboardAcknowledgement::TYPE_TASK,
            'sort_at' => $dueAt ?? $task->created_at,
            'priority_reason' => $isOverdue ? 'overdue' : ($isDueToday ? 'due_today' : 'open'),
            'label' => $isOverdue ? 'Overdue' : ($isDueToday ? 'Today' : 'Open'),
            'tone' => $isOverdue ? 'amber' : 'slate',
            'title' => $task->title,
            'subtitle' => trim(implode(' · ', array_filter([
                $relatedContact ? $this->contactName($relatedContact) : null,
                $this->dueLabel($dueAt),
                $task->assignedTo ? 'Owner: '.$this->modelName($task->assignedTo) : 'Unassigned',
            ]))),
            'description' => $task->description,
            'href' => $relatedContact ? route('crm.contacts.show', $relatedContact) : null,
            'action_label' => $relatedContact ? 'View' : null,
        ];
    }

    private function canBroadcastTaskList(): bool
    {
        return module_enabled('tasks')
            && module_enabled('messaging')
            && module_enabled('internal_notifications');
    }

    private function isDashboardItemAcknowledged(Request $request, string $itemType, string $itemKey): bool
    {
        $userId = $request->user()?->id;

        if (! $userId) {
            return false;
        }

        return DashboardAcknowledgement::query()
            ->active()
            ->where('user_id', $userId)
            ->where('surface', DashboardAcknowledgement::SURFACE_CRM_DASHBOARD)
            ->where('item_type', DashboardAcknowledgement::normalizeItemType($itemType))
            ->where('item_key', DashboardAcknowledgement::normalizeItemKey($itemKey))
            ->exists();
    }

    private function upcomingTaskSummaryKey(): string
    {
        return now(config('client.timezone', config('app.timezone', 'UTC')))->toDateString();
    }

    private function weekEnd(): Carbon
    {
        return now(config('client.timezone', config('app.timezone', 'UTC')))
            ->endOfWeek()
            ->endOfDay()
            ->utc();
    }

    private function dueLabel(mixed $date): ?string
    {
        if (! $date) {
            return 'No due date';
        }

        $localDate = $date->copy()->timezone(config('client.timezone', config('app.timezone', 'UTC')));
        $today = now(config('client.timezone', config('app.timezone', 'UTC')));

        if ($localDate->isBefore($today->copy()->startOfDay())) {
            return 'Due '.$localDate->format('M j, g:i A');
        }

        if ($localDate->isSameDay($today)) {
            return 'Due today '.$localDate->format('g:i A');
        }

        return 'Due '.$localDate->format('M j, g:i A');
    }

    private function contactName(Contact $contact): string
    {
        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name).' '.trim((string) $contact->last_name)
        )));

        return $name !== '' ? $name : ($contact->email ?: Str::title(config('contacts.labels.singular')).' #'.$contact->id);
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
