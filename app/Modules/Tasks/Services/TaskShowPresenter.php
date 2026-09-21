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
            : $links->firstWhere('kind', 'scheduled_message');

        return [
            'links' => $links,
            'contact' => $contact,
            'inbound' => $inbound,
            'outbound' => $outbound,
            'other_links' => $this->otherLinks($links),
            'origin' => $this->origin($task, $contact, $inbound, $outbound),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $links */
    private function otherLinks(Collection $links): Collection
    {
        return $links
            ->reject(fn (array $link): bool => in_array(
                $link['kind'] ?? null,
                ['contact', 'inbound_message', 'scheduled_message'],
                true,
            ))
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
                'template_label' => $template?->name ?: $template?->title,
                'template_url' => $template && Route::has('crm.tasks.templates.edit')
                    ? route('crm.tasks.templates.edit', $template)
                    : null,
                'missing_provenance' => false,
            ];
        }

        if ($task->source !== Task::SOURCE_MANUAL || $task->task_template_id !== null) {
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
                'template_label' => $template?->name ?: $template?->title ?: $task->task_template_key,
                'template_url' => $template && Route::has('crm.tasks.templates.edit')
                    ? route('crm.tasks.templates.edit', $template)
                    : null,
                'missing_provenance' => true,
            ];
        }

        return [
            'kind' => 'manual',
            'route_label' => null,
            'route_url' => null,
            'contact' => $contact,
            'status_label' => null,
            'inbound' => $inbound,
            'reply_summary' => null,
            'outbound' => $outbound,
            'template_label' => null,
            'template_url' => null,
            'missing_provenance' => false,
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}