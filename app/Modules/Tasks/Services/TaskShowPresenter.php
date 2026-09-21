<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class TaskShowPresenter
{
    public function __construct(
        private readonly TaskLinkPresentationResolver $links,
    ) {}

    /** @return array<string, mixed> */
    public function present(Task $task): array
    {
        $links = $this->links->forTask($task);
        $contact = $links->firstWhere('kind', 'contact');
        $inbound = $links->firstWhere('kind', 'inbound_message');
        $outbound = is_array($inbound['reply_to'] ?? null)
            ? $inbound['reply_to']
            : null;

        return [
            'links' => $links,
            'contact' => $contact,
            'inbound' => $inbound,
            'outbound' => $outbound,
            'other_links' => $this->otherLinks($links, $outbound),
            'origin' => $this->origin($task, $contact, $inbound, $outbound),
            'due_state' => $this->dueState($task),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $links
     * @param array<string, mixed>|null $conversationOutbound
     */
    private function otherLinks(Collection $links, ?array $conversationOutbound): Collection
    {
        $conversationOutboundId = is_numeric($conversationOutbound['scheduled_message_id'] ?? null)
            ? (int) $conversationOutbound['scheduled_message_id']
            : null;

        return $links
            ->reject(function (array $link) use ($conversationOutboundId): bool {
                $kind = $link['kind'] ?? null;

                if (in_array($kind, ['contact', 'inbound_message'], true)) {
                    return true;
                }

                if ($kind !== 'scheduled_message' || $conversationOutboundId === null) {
                    return false;
                }

                return is_numeric($link['scheduled_message_id'] ?? null)
                    && (int) $link['scheduled_message_id'] === $conversationOutboundId;
            })
            ->values();
    }

    /**
     * @param array<string, mixed>|null $contact
     * @param array<string, mixed>|null $inbound
     * @param array<string, mixed>|null $outbound
     * @return array<string, mixed>
     */
    private function origin(
        Task $task,
        ?array $contact,
        ?array $inbound,
        ?array $outbound,
    ): array {
        $provenance = data_get($task->meta, 'automation.provenance', []);
        $routeId = data_get($provenance, 'flow_route_id');
        $routeName = $this->string(data_get($provenance, 'flow_route_name'));
        $routeKey = $this->string(data_get($provenance, 'flow_route_key'));
        $routeUrl = is_numeric($routeId)
            && module_enabled('flow_routes')
            && Route::has('crm.flow-routes.show')
            ? route('crm.flow-routes.show', (int) $routeId)
            : null;
        $template = $task->taskTemplate;
        $templateLabel = $template?->name ?: $template?->title ?: $task->task_template_key;
        $templateUrl = $template && Route::has('crm.tasks.templates.edit')
            ? route('crm.tasks.templates.edit', $template)
            : null;

        if ($task->source === Task::SOURCE_MANUAL) {
            return [
                'kind' => 'manual',
                'route_label' => null,
                'route_url' => null,
                'contact' => $contact,
                'status_label' => null,
                'inbound' => $inbound,
                'reply_summary' => null,
                'outbound' => null,
                'template_label' => $templateLabel,
                'template_url' => $templateUrl,
            ];
        }

        if ($routeId !== null || $routeName !== null || $routeKey !== null) {
            return [
                'kind' => 'automation',
                'route_label' => $routeName ?: ($routeKey ?: 'Automatic route'),
                'route_url' => $routeUrl,
                'contact' => $contact,
                'status_label' => $this->string(data_get($provenance, 'contact_status_name'))
                    ?: $this->string(data_get($provenance, 'contact_status_key')),
                'inbound' => $inbound,
                'reply_summary' => filled($inbound['message'] ?? null)
                    ? Str::limit(trim((string) $inbound['message']), 90)
                    : null,
                'outbound' => $outbound,
                'template_label' => $templateLabel,
                'template_url' => $templateUrl,
            ];
        }

        return [
            'kind' => 'automation',
            'route_label' => null,
            'route_url' => null,
            'contact' => $contact,
            'status_label' => null,
            'inbound' => $inbound,
            'reply_summary' => filled($inbound['message'] ?? null)
                ? Str::limit(trim((string) $inbound['message']), 90)
                : null,
            'outbound' => $outbound,
            'template_label' => $templateLabel,
            'template_url' => $templateUrl,
        ];
    }

    private function dueState(Task $task): ?string
    {
        if ($task->status !== Task::STATUS_OPEN || $task->due_at === null) {
            return null;
        }

        $timezone = config('client.timezone', config('app.timezone', 'UTC'));
        $dueAt = $task->due_at->copy()->timezone($timezone);
        $today = now($timezone);

        if ($dueAt->isSameDay($today)) {
            return 'today';
        }

        return $dueAt->lt($today->copy()->startOfDay())
            ? 'overdue'
            : 'upcoming';
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}