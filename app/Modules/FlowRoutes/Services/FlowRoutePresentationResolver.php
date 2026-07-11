<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class FlowRoutePresentationResolver
{
    /** @var array<string, string>|null */
    private ?array $statusNamesByKey = null;

    /**
     * @return array<string, mixed>
     */
    public function route(FlowRoute $route): array
    {
        $points = $route->relationLoaded('activeFlowRoutePoints')
            ? $route->activeFlowRoutePoints
            : $route->activeFlowRoutePoints()->with('capability')->get();

        $activeBindings = $route->relationLoaded('activeTriggerBindings')
            ? $route->activeTriggerBindings
            : $route->activeTriggerBindings()->get();

        $kind = $points->count() === 1
            && $route->trigger_type === FlowRoute::TRIGGER_AUTOMATION_EVENT
                ? 'automatic_action'
                : 'route';
        $group = $this->groupForRoute($route);
        $summaryPoints = $this->routeSummaryPoints($route, $points);

        return [
            'id' => (int) $route->getKey(),
            'key' => (string) $route->key,
            'name' => (string) $route->name,
            'description' => (string) ($route->description ?: data_get($route->meta, 'description', '')),
            'compact_summary' => $this->compactSummary($route, $summaryPoints),
            'version' => (int) $route->version,
            'is_active' => (bool) $route->is_active,
            'is_current_version' => (bool) $route->is_current_version,
            'kind' => $kind,
            'kind_label' => $kind === 'route' ? 'Route' : 'Automatic action',
            'group_key' => $group['key'],
            'group_label' => $group['label'],
            'trigger_type' => (string) $route->trigger_type,
            'trigger_key' => (string) ($route->trigger_key ?? ''),
            'trigger_summary' => $this->triggerSummary($route),
            'assignment_count' => $activeBindings->count(),
            'assignment_summary' => $this->assignmentSummary($activeBindings),
            'assignment_query' => $this->assignmentQuery($route),
            'assignment_anchor' => $this->assignmentAnchor($route),
            'point_count' => $points->count(),
            'summary_points' => $summaryPoints,
            'presented_points' => $this->presentedPoints($route, $points),
            'has_campaign_enrollment' => $points->contains(
                fn (FlowRoutePoint $point): bool => $point->type === FlowRoutePointType::EnrollCampaign->value,
            ),
            'source_label' => $this->sourceLabel($route, $group),
            'internal' => [
                'owner_group' => $route->owner_group ? (string) $route->owner_group : null,
                'source_version' => $route->source_version ? (string) $route->source_version : null,
                'is_customized' => (bool) $route->is_customized,
            ],
        ];
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     module_key: string,
     *     type_label: string,
     *     label: string|null,
     *     summary: string,
     *     condition_summaries: array<int, string>
     * }>
     */
    public function presentedPoints(FlowRoute $route, ?Collection $points = null): array
    {
        $points ??= $route->activeFlowRoutePoints()->with('capability')->get();

        return $points
            ->sortBy('sort_order')
            ->values()
            ->map(function (FlowRoutePoint $point) use ($route): array {
                $summaries = $this->pointSummaries($point, $route);

                return [
                    'key' => (string) $point->key,
                    'type' => (string) $point->type,
                    'module_key' => $this->pointModuleKey($point),
                    'type_label' => $this->pointTypeLabel($point->type),
                    'label' => $this->meaningfulPointLabel($point),
                    'summary' => $this->pointEditorSummary($point, $route),
                    'condition_summaries' => array_slice($summaries, 1),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function routeSummaryPoints(FlowRoute $route, ?Collection $points = null): array
    {
        $points ??= $route->activeFlowRoutePoints()->get();

        return $points
            ->sortBy('sort_order')
            ->values()
            ->flatMap(fn (FlowRoutePoint $point): array => $this->pointSummaries($point, $route))
            ->filter(fn (mixed $summary): bool => is_string($summary) && trim($summary) !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function pointSummaries(FlowRoutePoint $point, ?FlowRoute $route = null): array
    {
        $route ??= $point->flowRoute;
        $definition = is_array($point->definition) ? $point->definition : [];
        $settings = is_array($point->settings) ? $point->settings : [];

        $primary = match ($point->type) {
            FlowRoutePointType::ChangeStatus->value => $this->changeStatusSummary($definition),
            FlowRoutePointType::EnrollCampaign->value => 'Start Campaign: '.$this->humanConfigLabel((string) data_get($definition, 'campaign_key', 'selected')).'.',
            FlowRoutePointType::CancelCampaign->value => 'Stop Campaign: '.$this->humanConfigLabel((string) data_get($definition, 'campaign_key', 'selected')).'.',
            FlowRoutePointType::CreateTask->value => 'Create task: '.$this->taskLabel($point, $definition).'.',
            FlowRoutePointType::SendMessage->value => 'Send a message.',
            FlowRoutePointType::Wait->value => $this->waitSummary($definition, $settings),
            FlowRoutePointType::EventWait->value => 'Wait until '.$this->humanAutomationEvent((string) data_get($definition, 'event_key', 'the next activity')).'.',
            FlowRoutePointType::Condition->value => 'Check conditions before continuing.',
            FlowRoutePointType::BranchEvaluate->value => 'Choose the next path based on conditions.',
            FlowRoutePointType::Noop->value => 'No action.',
            default => (string) ($point->description ?: $point->name),
        };

        return array_values(array_filter([
            $primary,
            ...$this->cancelConditionSummaries($point, $route),
        ]));
    }

    public function triggerSummary(FlowRoute $route): string
    {
        return match ($route->trigger_type) {
            FlowRoute::TRIGGER_CONTACT_STATUS => 'When a '.config('contacts.labels.singular', 'contact').' moves to '.$this->statusName((string) $route->trigger_key).'.',
            FlowRoute::TRIGGER_AUTOMATION_EVENT => 'When '.$this->humanAutomationEvent((string) $route->trigger_key).'.',
            FlowRoute::TRIGGER_MANUAL => 'Started manually.',
            default => Str::headline((string) $route->trigger_type).'.',
        };
    }

    private function meaningfulPointLabel(FlowRoutePoint $point): ?string
    {
        $label = trim((string) $point->name);

        if ($label === '') {
            return null;
        }

        $normalized = Str::lower($label);

        if (in_array($normalized, [
            'wait',
            'send message',
            'create task',
            'change contact status',
            'start campaign',
            'stop campaign',
        ], true)) {
            return null;
        }

        foreach ([
            'create task:',
            'create task from ',
            'change status to ',
            'start campaign:',
            'stop campaign:',
        ] as $generatedPrefix) {
            if (str_starts_with($normalized, $generatedPrefix)) {
                return null;
            }
        }

        return $label;
    }

    private function pointTypeLabel(string $pointType): string
    {
        return match ($pointType) {
            FlowRoutePointType::Wait->value => 'Wait',
            FlowRoutePointType::ChangeStatus->value => 'Status',
            FlowRoutePointType::CreateTask->value => 'Task',
            FlowRoutePointType::SendMessage->value => 'Message',
            FlowRoutePointType::EnrollCampaign->value,
            FlowRoutePointType::CancelCampaign->value => 'Campaign',
            default => Str::headline($pointType),
        };
    }

    private function pointEditorSummary(FlowRoutePoint $point, FlowRoute $route): string
    {
        $definition = is_array($point->definition) ? $point->definition : [];
        $settings = is_array($point->settings) ? $point->settings : [];

        return match ($point->type) {
            FlowRoutePointType::Wait->value => Str::of($this->waitSummary($definition, $settings))->rtrim('.')->toString(),
            FlowRoutePointType::ChangeStatus->value => Str::of($this->changeStatusSummary($definition))->rtrim('.')->toString(),
            FlowRoutePointType::CreateTask->value => $this->taskLabel($point, $definition),
            FlowRoutePointType::SendMessage->value => $this->humanConfigLabel((string) data_get($definition, 'message_template_preset_key', 'Selected message')),
            FlowRoutePointType::EnrollCampaign->value => 'Start '.$this->humanConfigLabel((string) data_get($definition, 'campaign_key', 'selected Campaign')),
            FlowRoutePointType::CancelCampaign->value => 'Stop '.$this->humanConfigLabel((string) data_get($definition, 'campaign_key', 'selected Campaign')),
            default => (string) ($point->description ?: $point->name),
        };
    }

    private function compactSummary(FlowRoute $route, array $summaryPoints): string
    {
        $description = trim((string) ($route->description ?: data_get($route->meta, 'description', '')));

        if ($description !== '') {
            return $description;
        }

        return (string) ($summaryPoints[0] ?? 'Review this Route to see what it does.');
    }

    private function waitSummary(array $definition, array $settings): string
    {
        foreach (['weeks', 'days', 'hours', 'minutes', 'seconds'] as $unit) {
            $value = $definition[$unit] ?? $settings[$unit] ?? null;

            if (is_numeric($value)) {
                return 'Wait '.$this->quantity((int) $value, rtrim($unit, 's')).'.';
            }
        }

        $resumeAt = $definition['resume_at'] ?? $settings['resume_at'] ?? null;

        if (is_string($resumeAt) && trim($resumeAt) !== '') {
            try {
                $date = CarbonImmutable::parse($resumeAt);

                return 'Wait until '.$date->format('M j, Y \a\t g:i A').'.';
            } catch (Throwable) {
                return 'Wait until the scheduled time.';
            }
        }

        $parsed = WaitPointDefinition::from($definition, $settings);
        $seconds = $parsed->source['seconds'] ?? null;

        if (is_numeric($seconds)) {
            return 'Wait '.$this->durationFromSeconds((int) $seconds).'.';
        }

        return 'Wait before continuing.';
    }

    /**
     * @return array<int, string>
     */
    private function cancelConditionSummaries(FlowRoutePoint $point, FlowRoute $route): array
    {
        $conditions = is_array($point->cancel_conditions) ? $point->cancel_conditions : [];
        $summaries = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $type = (string) ($condition['type'] ?? '');

            if ($type === 'contact_status_changed') {
                $statusKey = (string) ($condition['contact_status_key'] ?? $route->trigger_key ?? '');
                $statusName = $statusKey !== '' ? $this->statusName($statusKey) : 'the current status';
                $summaries[] = 'Continue only while the '.config('contacts.labels.singular', 'contact').' remains in '.$statusName.'.';
            }
        }

        return array_values(array_unique($summaries));
    }

    private function changeStatusSummary(array $definition): string
    {
        $statusKey = (string) data_get($definition, 'contact_status_key', '');

        if ($statusKey === '') {
            return 'Update the status.';
        }

        return 'Move the '.config('contacts.labels.singular', 'contact').' to '.$this->statusName($statusKey).'.';
    }

    private function taskLabel(FlowRoutePoint $point, array $definition): string
    {
        $title = data_get($definition, 'title');

        if (is_string($title) && trim($title) !== '') {
            return trim($title);
        }

        return (string) $point->name;
    }

    private function statusName(string $statusKey): string
    {
        if ($statusKey === '') {
            return 'the selected status';
        }

        $this->statusNamesByKey ??= ContactStatus::query()
            ->pluck('name', 'key')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();

        return $this->statusNamesByKey[$statusKey] ?? Str::headline($statusKey);
    }

    private function humanAutomationEvent(string $eventKey): string
    {
        return match ($eventKey) {
            'webinar.attended' => 'someone attends a webinar',
            'webinar.missed' => 'someone misses a webinar',
            'webinar.registered' => 'someone registers for a webinar',
            'webinar.cancelled' => 'someone cancels a webinar registration',
            'webinar.ended' => 'a webinar ends',
            'task.completed' => 'a task is completed',
            'permission_invitation.accepted' => 'someone confirms their communication preferences',
            'inbound_message.normal_reply' => 'someone replies to a message',
            default => Str::of($eventKey)->replace(['.', '_'], ' ')->lower()->toString(),
        };
    }

    private function humanConfigLabel(string $value): string
    {
        if ($value === '') {
            return 'selected';
        }

        return Str::of($value)
            ->replace(['_', '-'], ' ')
            ->headline()
            ->toString();
    }

    private function durationFromSeconds(int $seconds): string
    {
        foreach ([
            'week' => 604800,
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
        ] as $unit => $unitSeconds) {
            if ($seconds >= $unitSeconds && $seconds % $unitSeconds === 0) {
                return $this->quantity(intdiv($seconds, $unitSeconds), $unit);
            }
        }

        return $this->quantity($seconds, 'second');
    }

    private function quantity(int $value, string $unit): string
    {
        return $value.' '.$unit.($value === 1 ? '' : 's');
    }

    private function pointModuleKey(FlowRoutePoint $point): string
    {
        if ($point->relationLoaded('capability') && $point->capability?->module_key) {
            return (string) $point->capability->module_key;
        }

        return match ($point->type) {
            FlowRoutePointType::ChangeStatus->value => 'workflow',
            FlowRoutePointType::CreateTask->value => 'tasks',
            FlowRoutePointType::SendMessage->value => 'messaging',
            FlowRoutePointType::EnrollCampaign->value,
            FlowRoutePointType::CancelCampaign->value => 'campaigns',
            default => 'flow_routes',
        };
    }

    /**
     * @return array{key: string, label: string}
     */
    private function groupForRoute(FlowRoute $route): array
    {
        if ($route->trigger_type === FlowRoute::TRIGGER_AUTOMATION_EVENT) {
            $moduleKey = $this->automationEventModuleKey((string) $route->trigger_key);

            return [
                'key' => $moduleKey,
                'label' => (string) config("modules.modules.{$moduleKey}.name", Str::headline($moduleKey)),
            ];
        }

        if ($route->trigger_type === FlowRoute::TRIGGER_CONTACT_STATUS) {
            return [
                'key' => 'statuses',
                'label' => 'Status changes',
            ];
        }

        return [
            'key' => 'manual',
            'label' => 'Manual',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function assignmentQuery(FlowRoute $route): array
    {
        return match ($route->trigger_type) {
            FlowRoute::TRIGGER_CONTACT_STATUS => [
                'tab' => 'status',
                'status' => (string) $route->trigger_key,
            ],
            FlowRoute::TRIGGER_AUTOMATION_EVENT => [
                'tab' => 'activity',
                'module' => $this->automationEventModuleKey((string) $route->trigger_key),
                'event' => (string) $route->trigger_key,
            ],
            default => [],
        };
    }

    private function automationEventModuleKey(string $eventKey): string
    {
        return match (Str::before($eventKey, '.')) {
            'webinar' => 'webinars',
            'task' => 'tasks',
            'permission_invitation' => 'messaging',
            'inbound_message' => 'inbound_messaging',
            default => Str::before($eventKey, '.') ?: 'other',
        };
    }

    private function assignmentAnchor(FlowRoute $route): ?string
    {
        return match ($route->trigger_type) {
            FlowRoute::TRIGGER_CONTACT_STATUS => 'status-'.Str::slug((string) $route->trigger_key),
            FlowRoute::TRIGGER_AUTOMATION_EVENT => 'event-'.Str::of((string) $route->trigger_key)->replace('.', '-')->slug()->toString(),
            default => null,
        };
    }

    /**
     * @param array{key: string, label: string} $group
     */
    private function sourceLabel(FlowRoute $route, array $group): string
    {
        if ($route->is_customized) {
            return 'Customized';
        }

        if ($route->source_version) {
            return 'Preset';
        }

        if ($route->owner_group) {
            return Str::headline((string) $route->owner_group);
        }

        return $group['label'];
    }

    private function assignmentSummary(Collection $bindings): string
    {
        $count = $bindings->count();

        return match ($count) {
            0 => 'Not assigned',
            1 => 'Assigned to 1 trigger',
            default => "Assigned to {$count} triggers",
        };
    }
}
