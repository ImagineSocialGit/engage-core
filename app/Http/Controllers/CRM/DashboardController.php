<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\DashboardAcknowledgement;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Tasks\Models\Task;
use App\Modules\Webinars\Models\WebinarRegistration;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const TASK_BROADCAST_MESSAGE_TYPE = 'dashboard_task_list';

    public function index(): View
    {
        $todayStart = $this->todayStart();
        $todayEnd = $this->todayEnd();

        $taskData = $this->taskData($todayStart, $todayEnd);
        $leadData = $this->leadReplyData();
        $webinarData = $this->webinarRegistrationData();

        return view('crm.dashboard', [
            'title' => 'Dashboard',
            'heading' => 'Today',
            'subheading' => 'A clear place to start, without turning the CRM into a cockpit.',
            'summary' => [
                'attention_count' => $this->attentionCount($taskData, $leadData),
                'task_count' => $taskData['attention_count'],
                'overdue_tasks' => $taskData['overdue_count'],
                'due_today_tasks' => $taskData['due_today_count'],
                'lead_replies' => $leadData['recent_count'],
                'webinar_activity' => $webinarData['recent_count'],
            ],
            'primaryAction' => $this->primaryAction(
                tasks: $taskData['items'],
                leadReplies: $leadData['items'],
                webinarRegistrations: $webinarData['items'],
            ),
            'taskItems' => $taskData['items']->take(6)->values(),
            'leadItems' => $leadData['items']->take(6)->values(),
            'webinarItems' => $webinarData['items']->take(5)->values(),
            'canBroadcastTaskList' => $this->canBroadcastTaskList(),
        ]);
    }

    public function printTasks(): View
    {
        $todayStart = $this->todayStart();
        $todayEnd = $this->todayEnd();
        $tasks = $this->taskModels($todayStart, $todayEnd, 50);

        return view('crm.dashboard-tasks-print', [
            'title' => 'Today’s Task List',
            'printedAt' => now(config('client.timezone', config('app.timezone', 'UTC'))),
            'tasks' => $tasks,
            'taskItems' => $tasks->map(fn (Task $task): array => $this->taskItem($task, $todayStart, $todayEnd))->values(),
            'overdueCount' => $tasks->filter(fn (Task $task): bool => $task->due_at?->lt($todayStart) ?? false)->count(),
            'dueTodayCount' => $tasks->filter(fn (Task $task): bool => $task->due_at?->betweenIncluded($todayStart, $todayEnd) ?? false)->count(),
        ]);
    }

    public function broadcastTasks(ScheduleInternalNotificationAction $scheduleInternalNotification): RedirectResponse
    {
        if (! $this->canBroadcastTaskList()) {
            return redirect()
                ->route('crm.index')
                ->with('error', 'Task sharing is available when Tasks, Team Members, Messaging, and Internal Notifications are enabled.');
        }

        $todayStart = $this->todayStart();
        $todayEnd = $this->todayEnd();
        $tasks = $this->taskModels($todayStart, $todayEnd, 50);

        if ($tasks->isEmpty()) {
            return redirect()
                ->route('crm.index')
                ->with('error', 'There are no open tasks to share right now.');
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
                content: $this->taskBroadcastContent($tasks, $teamMember),
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
     * @return array<string, mixed>
     */
    private function taskData(Carbon $todayStart, Carbon $todayEnd): array
    {
        if (! module_enabled('tasks')) {
            return [
                'items' => collect(),
                'attention_count' => 0,
                'overdue_count' => 0,
                'due_today_count' => 0,
            ];
        }

        $baseQuery = $this->baseTaskQuery();

        $overdueCount = (clone $baseQuery)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $todayStart)
            ->count();

        $dueTodayCount = (clone $baseQuery)
            ->whereBetween('due_at', [$todayStart, $todayEnd])
            ->count();

        $tasks = $this->taskModels($todayStart, $todayEnd, 10);

        return [
            'items' => $tasks->map(fn (Task $task): array => $this->taskItem($task, $todayStart, $todayEnd))->values(),
            'attention_count' => $overdueCount + $dueTodayCount,
            'overdue_count' => $overdueCount,
            'due_today_count' => $dueTodayCount,
        ];
    }

    /**
     * @return Collection<int, Task>
     */
    private function taskModels(Carbon $todayStart, Carbon $todayEnd, int $limit): Collection
    {
        if (! module_enabled('tasks')) {
            return collect();
        }

        return $this->baseTaskQuery()
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->latest('id')
            ->limit($limit)
            ->get();
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
    private function leadReplyData(): array
    {
        if (! module_enabled('inbound_messaging')) {
            return [
                'items' => collect(),
                'recent_count' => 0,
            ];
        }

        $acknowledgedIds = $this->acknowledgedItemKeys(DashboardAcknowledgement::TYPE_INBOUND_MESSAGE);

        $baseQuery = InboundMessage::query()
            ->with('sender')
            ->where('classification', InboundMessage::CLASSIFICATION_NORMAL_REPLY)
            ->where('received_at', '>=', now()->subDays(3))
            ->when($acknowledgedIds !== [], fn (Builder $query) => $query->whereNotIn('id', $acknowledgedIds));

        $recentCount = (clone $baseQuery)->count();

        $replies = (clone $baseQuery)
            ->latest('received_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return [
            'items' => $replies->map(fn (InboundMessage $message): array => $this->leadReplyItem($message))->values(),
            'recent_count' => $recentCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webinarRegistrationData(): array
    {
        if (! module_enabled('webinars')) {
            return [
                'items' => collect(),
                'recent_count' => 0,
            ];
        }

        $acknowledgedIds = $this->acknowledgedItemKeys(DashboardAcknowledgement::TYPE_WEBINAR_REGISTRATION);

        $baseQuery = WebinarRegistration::query()
            ->with(['contact', 'webinar.webinarSeries'])
            ->where('registered_at', '>=', now()->subDays(7))
            ->when($acknowledgedIds !== [], fn (Builder $query) => $query->whereNotIn('id', $acknowledgedIds));

        $recentCount = (clone $baseQuery)->count();

        $registrations = (clone $baseQuery)
            ->latest('registered_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return [
            'items' => $registrations
                ->map(fn (WebinarRegistration $registration): array => $this->webinarRegistrationItem($registration))
                ->values(),
            'recent_count' => $recentCount,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $tasks
     * @param Collection<int, array<string, mixed>> $leadReplies
     * @param Collection<int, array<string, mixed>> $webinarRegistrations
     * @return array<string, mixed>|null
     */
    private function primaryAction(
        Collection $tasks,
        Collection $leadReplies,
        Collection $webinarRegistrations,
    ): ?array {
        $overdueTask = $tasks->first(fn (array $item): bool => ($item['priority_reason'] ?? null) === 'overdue');

        if ($overdueTask) {
            return [
                'label' => $overdueTask['href'] ? 'Open overdue task' : 'Review tasks',
                'href' => $overdueTask['href'],
                'summary' => 'Start with the overdue task at the top of today’s list.',
            ];
        }

        $reply = $leadReplies->first();

        if ($reply) {
            return [
                'label' => $reply['href'] ? 'Work next '.config('contacts.labels.singular') : 'Review reply',
                'href' => $reply['href'],
                'summary' => 'A recent reply is the clearest '.config('contacts.labels.singular').' to review first.',
            ];
        }

        $dueTodayTask = $tasks->first(fn (array $item): bool => ($item['priority_reason'] ?? null) === 'due_today');

        if ($dueTodayTask) {
            return [
                'label' => $dueTodayTask['href'] ? 'Open today’s task' : 'Review tasks',
                'href' => $dueTodayTask['href'],
                'summary' => 'Start with the first task due today.',
            ];
        }

        return [
            'label' => 'View '.config('contacts.labels.plural'),
            'href' => route('crm.contacts.index'),
            'summary' => 'No urgent item is waiting. Review '.config('contacts.labels.plural').' when you are ready.',
        ];
    }

    /**
     * @param array<string, mixed> $taskData
     * @param array<string, mixed> $leadData
     */
    private function attentionCount(array $taskData, array $leadData): int
    {
        return (int) $taskData['attention_count'] + (int) $leadData['recent_count'];
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

    /**
     * @return array<string, mixed>
     */
    private function leadReplyItem(InboundMessage $message): array
    {
        $contact = $message->sender instanceof Contact ? $message->sender : null;
        $sender = $contact ? $this->contactName($contact) : ($message->from_value ?: 'Unknown sender');

        return [
            'key' => (string) $message->id,
            'type' => DashboardAcknowledgement::TYPE_INBOUND_MESSAGE,
            'sort_at' => $message->received_at ?? $message->created_at,
            'label' => 'New reply',
            'tone' => 'blue',
            'title' => $sender.' replied',
            'subtitle' => trim(implode(' · ', array_filter([
                strtoupper($this->enumValue($message->channel)),
                $this->dateLabel($message->received_at),
            ]))),
            'description' => $message->body,
            'href' => $contact ? route('crm.contacts.show', $contact) : null,
            'action_label' => $contact ? 'Review reply' : 'Review message',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webinarRegistrationItem(WebinarRegistration $registration): array
    {
        $contact = $registration->contact;
        $webinar = $registration->webinar;
        $contactName = $contact ? $this->contactName($contact) : 'A '.config('contacts.labels.singular');

        return [
            'key' => (string) $registration->id,
            'type' => DashboardAcknowledgement::TYPE_WEBINAR_REGISTRATION,
            'sort_at' => $registration->registered_at ?? $registration->created_at,
            'label' => 'Webinar signup',
            'tone' => 'emerald',
            'title' => $contactName.' registered',
            'subtitle' => trim(implode(' · ', array_filter([
                $webinar?->title,
                $this->dateLabel($registration->registered_at),
            ]))),
            'description' => $webinar?->starts_at
                ? 'Upcoming: '.$this->dateLabel($webinar->starts_at)
                : 'A new webinar registration came in.',
            'href' => $contact ? route('crm.contacts.show', $contact) : null,
            'action_label' => $contact ? 'Open '.config('contacts.labels.singular') : 'Review webinar',
        ];
    }

    /**
     * @param Collection<int, Task> $tasks
     * @return array<string, mixed>
     */
    private function taskBroadcastContent(Collection $tasks, TeamMember $teamMember): array
    {
        $count = $tasks->count();
        $lines = $tasks
            ->take(12)
            ->map(fn (Task $task): string => $this->taskBroadcastLine($task))
            ->when(
                $count > 12,
                fn (Collection $lines): Collection => $lines->push('And '.($count - 12).' more.')
            )
            ->values()
            ->all();

        return [
            'subject' => 'Today’s task list: '.$count.' open '.Str::plural('task', $count),
            'headline' => 'Today’s task list',
            'preheader' => $count.' open '.Str::plural('task', $count).' need attention or are next in line.',
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

    private function taskBroadcastLine(Task $task): string
    {
        $segments = [$task->title];

        if ($task->related instanceof Contact) {
            $segments[] = $this->contactName($task->related);
        }

        if ($task->due_at) {
            $segments[] = $this->dueLabel($task->due_at);
        } else {
            $segments[] = 'No due date';
        }

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

    /**
     * @return array<int, string>
     */
    private function acknowledgedItemKeys(string $itemType): array
    {
        $userId = auth()->id();

        if (! $userId) {
            return [];
        }

        return DashboardAcknowledgement::query()
            ->active()
            ->where('user_id', $userId)
            ->where('surface', DashboardAcknowledgement::SURFACE_CRM_DASHBOARD)
            ->where('item_type', DashboardAcknowledgement::normalizeItemType($itemType))
            ->pluck('item_key')
            ->values()
            ->all();
    }

    private function safeReturnTo(?string $returnTo): string
    {
        if (! is_string($returnTo) || trim($returnTo) === '') {
            return route('crm.index');
        }

        $returnTo = trim($returnTo);
        $appUrl = rtrim((string) config('app.url'), '/');

        if (str_starts_with($returnTo, '/')) {
            return $returnTo;
        }

        if ($appUrl !== '' && str_starts_with($returnTo, $appUrl.'/')) {
            return $returnTo;
        }

        return route('crm.index');
    }

    private function todayStart(): Carbon
    {
        return now(config('client.timezone', config('app.timezone', 'UTC')))
            ->startOfDay()
            ->utc();
    }

    private function todayEnd(): Carbon
    {
        return now(config('client.timezone', config('app.timezone', 'UTC')))
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

    private function dateLabel(mixed $date): ?string
    {
        return $date?->copy()
            ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
            ->format('M j, g:i A');
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
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
