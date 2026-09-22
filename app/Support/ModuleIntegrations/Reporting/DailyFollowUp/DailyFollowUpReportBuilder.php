<?php

namespace App\Support\ModuleIntegrations\Reporting\DailyFollowUp;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\Dashboard\SchedulingDashboardAppointments;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Support\Modules\ModuleManager;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class DailyFollowUpReportBuilder
{
    public function __construct(
        private readonly ModuleManager $modules,
    ) {}

    /**
     * @param array<string, mixed> $parameters
     * @return array{
     *     generated_at: Carbon,
     *     sections: array<int, array<string, mixed>>,
     *     total_count: int
     * }
     */
    public function build(
        array $parameters,
        CarbonInterface $now,
        string $timezone,
    ): array {
        $localNow = Carbon::instance($now)->copy()->timezone($timezone);
        $limit = max(1, min(50, (int) ($parameters['section_limit'] ?? 20)));

        $sections = array_values(array_filter([
            ($parameters['include_replies'] ?? true)
                ? $this->newReplies($limit)
                : null,
            $this->newLeads($parameters, $localNow, $limit),
            ($parameters['include_tasks'] ?? true)
                ? $this->dueTasks($localNow, $timezone, $limit)
                : null,
            ($parameters['include_appointments'] ?? true)
                ? $this->todayAppointments($timezone, $limit)
                : null,
            $this->incompleteApplications($parameters, $timezone, $limit),
            $this->withoutNextAction($parameters, $localNow, $timezone, $limit),
            $this->staleProspects($parameters, $localNow, $timezone, $limit),
        ]));

        return [
            'generated_at' => $localNow,
            'sections' => $sections,
            'total_count' => array_sum(array_map(
                static fn (array $section): int => (int) $section['count'],
                $sections,
            )),
        ];
    }

    private function newReplies(int $limit): ?array
    {
        if (! $this->moduleReady('inbound_messaging', ['inbound_messages'])) {
            return null;
        }

        $query = InboundMessage::query()
            ->with(['sender', 'relatedContact'])
            ->where('classification', InboundMessage::CLASSIFICATION_NORMAL_REPLY)
            ->whereIn('inbox_status', [
                InboundMessage::INBOX_STATUS_NEW,
                InboundMessage::INBOX_STATUS_REVIEWED,
            ]);

        return $this->section(
            key: 'new_replies',
            label: 'Replies needing attention',
            count: (clone $query)->count(),
            items: $query
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(function (InboundMessage $message): array {
                    $contact = $message->sender instanceof Contact
                        ? $message->sender
                        : ($message->relatedContact instanceof Contact
                            ? $message->relatedContact
                            : null);

                    return [
                        'title' => $this->contactLabel($contact)
                            ?? ($message->from_value ?: 'Unmatched inbound reply'),
                        'detail' => Str::limit(
                            trim((string) ($message->body ?: 'No message body')),
                            180,
                        ),
                        'url' => route(
                            'crm.inbound-messaging.inbox.show',
                            $message,
                        ),
                    ];
                })
                ->all(),
        );
    }

    private function newLeads(
        array $parameters,
        Carbon $localNow,
        int $limit,
    ): ?array {
        $keys = $this->stringList(
            $parameters['new_lead_status_keys'] ?? [],
        );

        if ($keys === [] || ! $this->workflowReady()) {
            return null;
        }

        $query = $this->contactsInStatuses($keys)
            ->where(
                'contacts.created_at',
                '>=',
                $localNow->copy()->subDay()->utc(),
            );

        return $this->contactSection(
            key: 'new_leads',
            label: 'New leads from the last 24 hours',
            query: $query,
            limit: $limit,
            detail: fn (Contact $contact): string =>
                'Added '.$this->dateTimeLabel(
                    $contact->created_at,
                    $localNow->timezoneName,
                ),
        );
    }

    private function dueTasks(
        Carbon $localNow,
        string $timezone,
        int $limit,
    ): ?array {
        if (! $this->moduleReady('tasks', ['tasks'])) {
            return null;
        }

        $query = Task::query()
            ->open()
            ->unarchived()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $localNow->copy()->endOfDay()->utc());

        return $this->section(
            key: 'due_tasks',
            label: 'Overdue and due-today tasks',
            count: (clone $query)->count(),
            items: $query
                ->orderBy('due_at')
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->map(fn (Task $task): array => [
                    'title' => $task->title,
                    'detail' => $this->taskDueLabel(
                        $task,
                        $localNow,
                        $timezone,
                    ),
                    'url' => route('crm.tasks.show', $task),
                ])
                ->all(),
        );
    }

    private function todayAppointments(
        string $timezone,
        int $limit,
    ): ?array {
        if (! $this->moduleReady('scheduling', ['appointments'])) {
            return null;
        }

        $summary = app(SchedulingDashboardAppointments::class)
            ->forLocalDay(0, $limit);

        return $this->section(
            key: 'appointments',
            label: 'Appointments today',
            count: (int) $summary['count'],
            items: $summary['appointments']
                ->map(fn (Appointment $appointment): array => [
                    'title' => $this->contactLabel($appointment->contact)
                        ?? trim((string) ($appointment->title ?: 'Appointment')),
                    'detail' => $appointment->starts_at
                        ? $appointment->starts_at
                            ->copy()
                            ->timezone($timezone)
                            ->format('g:i A T')
                        : null,
                    'url' => route(
                        'crm.scheduling.appointments.show',
                        $appointment,
                    ),
                ])
                ->all(),
        );
    }

    private function incompleteApplications(
        array $parameters,
        string $timezone,
        int $limit,
    ): ?array {
        $keys = $this->stringList(
            $parameters['incomplete_application_status_keys'] ?? [],
        );

        if ($keys === [] || ! $this->workflowReady()) {
            return null;
        }

        return $this->contactSection(
            key: 'incomplete_applications',
            label: 'Incomplete applications',
            query: $this->contactsInStatuses($keys),
            limit: $limit,
            detail: fn (Contact $contact): string =>
                'Last activity '.$this->dateTimeLabel(
                    $contact->last_activity_at ?? $contact->updated_at,
                    $timezone,
                ),
        );
    }

    private function withoutNextAction(
        array $parameters,
        Carbon $localNow,
        string $timezone,
        int $limit,
    ): ?array {
        $keys = $this->stringList(
            $parameters['next_action_status_keys'] ?? [],
        );

        if ($keys === []
            || ! $this->workflowReady()
            || ! $this->moduleReady('tasks', ['tasks', 'task_links'])
        ) {
            return null;
        }

        $contactMorph = (new Contact())->getMorphClass();
        $query = $this->contactsInStatuses($keys);

        $query->whereNotExists(function ($subquery) use ($contactMorph): void {
            $subquery
                ->selectRaw('1')
                ->from('task_links')
                ->join('tasks', 'tasks.id', '=', 'task_links.task_id')
                ->whereColumn('task_links.linkable_id', 'contacts.id')
                ->where('task_links.linkable_type', $contactMorph)
                ->whereIn('task_links.role', [
                    TaskLink::ROLE_SUBJECT,
                    TaskLink::ROLE_CONTEXT,
                ])
                ->where('tasks.status', Task::STATUS_OPEN)
                ->whereNull('tasks.archived_at');
        });

        if ($this->moduleReady('scheduling', ['appointments'])) {
            $nowUtc = $localNow->copy()->utc();

            $query->whereNotExists(function ($subquery) use ($nowUtc): void {
                $subquery
                    ->selectRaw('1')
                    ->from('appointments')
                    ->whereColumn('appointments.contact_id', 'contacts.id')
                    ->whereIn('appointments.status', [
                        Appointment::STATUS_PENDING,
                        Appointment::STATUS_SCHEDULED,
                        Appointment::STATUS_CONFIRMED,
                    ])
                    ->where('appointments.ends_at', '>=', $nowUtc)
                    ->whereNull('appointments.deleted_at');
            });
        }

        return $this->contactSection(
            key: 'no_next_action',
            label: 'Leads with no next action scheduled',
            query: $query,
            limit: $limit,
            detail: fn (Contact $contact): string =>
                'Last contacted '.$this->contactedLabel($contact, $timezone),
        );
    }

    private function staleProspects(
        array $parameters,
        Carbon $localNow,
        string $timezone,
        int $limit,
    ): ?array {
        $rules = is_array($parameters['follow_up_status_days'] ?? null)
            ? $parameters['follow_up_status_days']
            : [];

        if ($rules === [] || ! $this->workflowReady()) {
            return null;
        }

        $contactIds = collect();

        foreach ($rules as $statusKey => $days) {
            if (! is_string($statusKey)
                || trim($statusKey) === ''
                || ! is_numeric($days)
                || (int) $days < 1
            ) {
                continue;
            }

            $threshold = $localNow
                ->copy()
                ->subDays((int) $days)
                ->utc();

            $ids = $this->contactsInStatuses([trim($statusKey)])
                ->where(function (Builder $query) use ($threshold): void {
                    $query
                        ->where('contacts.last_contacted_at', '<=', $threshold)
                        ->orWhere(function (Builder $query) use ($threshold): void {
                            $query
                                ->whereNull('contacts.last_contacted_at')
                                ->where('contacts.created_at', '<=', $threshold);
                        });
                })
                ->pluck('contacts.id');

            $contactIds = $contactIds->merge($ids);
        }

        $contactIds = $contactIds
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $query = Contact::query()->whereIn('id', $contactIds);

        return $this->contactSection(
            key: 'stale_prospects',
            label: 'Prospects overdue for personal follow-up',
            query: $query,
            limit: $limit,
            detail: fn (Contact $contact): string =>
                'Last contacted '.$this->contactedLabel($contact, $timezone),
        );
    }

    /** @param array<int, string> $statusKeys */
    private function contactsInStatuses(array $statusKeys): Builder
    {
        return Contact::query()
            ->select('contacts.*')
            ->join(
                'contact_workflow_profiles as scheduled_report_workflow',
                'scheduled_report_workflow.contact_id',
                '=',
                'contacts.id',
            )
            ->join(
                'contact_statuses as scheduled_report_status',
                'scheduled_report_status.id',
                '=',
                'scheduled_report_workflow.contact_status_id',
            )
            ->whereIn('scheduled_report_status.key', $statusKeys)
            ->whereNull('contacts.deleted_at');
    }

    private function contactSection(
        string $key,
        string $label,
        Builder $query,
        int $limit,
        callable $detail,
    ): array {
        return $this->section(
            key: $key,
            label: $label,
            count: (clone $query)->count(),
            items: $query
                ->orderBy('contacts.name')
                ->orderBy('contacts.id')
                ->limit($limit)
                ->get()
                ->map(fn (Contact $contact): array => [
                    'title' => $this->contactLabel($contact)
                        ?? 'Contact #'.$contact->getKey(),
                    'detail' => $detail($contact),
                    'url' => route('crm.contacts.show', $contact),
                ])
                ->all(),
        );
    }

    private function section(
        string $key,
        string $label,
        int $count,
        array $items,
    ): array {
        return compact('key', 'label', 'count', 'items');
    }

    private function moduleReady(string $module, array $tables): bool
    {
        if (! $this->modules->enabled($module)) {
            return false;
        }

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function workflowReady(): bool
    {
        return $this->moduleReady(
            'workflow',
            ['contact_workflow_profiles', 'contact_statuses'],
        );
    }

    private function contactLabel(?Contact $contact): ?string
    {
        if (! $contact instanceof Contact) {
            return null;
        }

        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name)
            .' '.
            trim((string) $contact->last_name),
        )));

        return $name !== ''
            ? $name
            : (filled($contact->email) ? (string) $contact->email : null);
    }

    private function contactedLabel(Contact $contact, string $timezone): string
    {
        return $contact->last_contacted_at
            ? $this->dateTimeLabel($contact->last_contacted_at, $timezone)
            : 'never';
    }

    private function dateTimeLabel(mixed $value, string $timezone): string
    {
        if ($value === null) {
            return 'unknown';
        }

        $carbon = $value instanceof CarbonInterface
            ? Carbon::instance($value)
            : Carbon::parse((string) $value);

        return $carbon->timezone($timezone)->format('M j, g:i A T');
    }

    private function taskDueLabel(
        Task $task,
        Carbon $localNow,
        string $timezone,
    ): ?string {
        if (! $task->due_at) {
            return null;
        }

        $due = $task->due_at->copy()->timezone($timezone);

        return $due->lt($localNow)
            ? 'Overdue since '.$due->format('M j, g:i A T')
            : 'Due '.$due->format('g:i A T');
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($item): string => is_string($item)
                ? trim($item)
                : '',
            $value,
        ))));
    }
}