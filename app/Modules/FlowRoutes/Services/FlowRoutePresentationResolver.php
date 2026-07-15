<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FlowRoutePresentationResolver
{
    /** @var array<string, string>|null */
    private ?array $statusNamesByKey = null;

    public function __construct(
        private readonly AutomationPointAuthoringRegistry $authoring,
    ) {}

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
                    'fields' => $this->authoring->has((string) $point->type)
                        ? $this->authoring->fields(
                            (string) $point->type,
                            is_array($point->definition) ? $point->definition : [],
                            $this->authoringContext($route, $point),
                        )
                        : [],
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
        $primary = $this->authoring->has((string) $point->type)
            ? $this->authoring->summary(
                (string) $point->type,
                $definition,
                $this->authoringContext($route, $point),
            )
            : match ($point->type) {
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
        $definition = $this->authoring->get((string) $point->type);

        if ($definition !== null) {
            $genericLabels = array_map(
                fn (string $candidate): string => Str::lower(trim($candidate)),
                $definition->genericLabels,
            );

            if (in_array($normalized, $genericLabels, true)) {
                return null;
            }

            foreach ($definition->generatedPrefixes as $generatedPrefix) {
                if (str_starts_with($normalized, Str::lower(trim($generatedPrefix)))) {
                    return null;
                }
            }
        }

        return $label;
    }

    private function pointTypeLabel(string $pointType): string
    {
        $definition = $this->authoring->get($pointType);

        return $definition?->typeLabel ?: Str::headline($pointType);
    }

    private function pointEditorSummary(FlowRoutePoint $point, FlowRoute $route): string
    {
        $definition = is_array($point->definition) ? $point->definition : [];

        if ($this->authoring->has((string) $point->type)) {
            return $this->authoring->editorSummary(
                (string) $point->type,
                $definition,
                $this->authoringContext($route, $point),
            );
        }

        return (string) ($point->description ?: $point->name);
    }

    private function compactSummary(FlowRoute $route, array $summaryPoints): string
    {
        $description = trim((string) ($route->description ?: data_get($route->meta, 'description', '')));

        if ($description !== '') {
            return $description;
        }

        return (string) ($summaryPoints[0] ?? 'Review this Route to see what it does.');
    }

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

    private function authoringContext(
        FlowRoute $route,
        ?FlowRoutePoint $point = null,
    ): AutomationPointAuthoringContext {
        return new AutomationPointAuthoringContext(
            existingPointTypes: $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->pluck('type')
                ->map(fn (mixed $type): string => (string) $type)
                ->all(),
            container: $route,
            point: $point,
            capability: $point?->capability,
        );
    }

    private function pointModuleKey(FlowRoutePoint $point): string
    {
        if ($point->relationLoaded('capability') && $point->capability?->module_key) {
            return (string) $point->capability->module_key;
        }

        return $this->authoring->get((string) $point->type)?->moduleKey ?? 'flow_routes';
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
