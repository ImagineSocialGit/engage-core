<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\ReplyHandling\ReplyProfilePresentationRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FlowRoutePresentationResolver
{
    /** @var array<string, string>|null */
    private ?array $statusNamesByKey = null;

    public function __construct(
        private readonly AutomationPointAuthoringRegistry $authoring,
        private readonly ReplyProfilePresentationRegistry $replyProfiles,
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

        $kind = $route->authoringKind();
        $group = $this->groupForRoute($route);
        $summaryPoints = $this->routeSummaryPoints($route, $points);
        $entryConditions = $this->entryConditions($route);

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
            'kind_label' => $kind === FlowRoute::AUTHORING_KIND_ROUTE ? 'Route' : 'Automatic behavior',
            'group_key' => $group['key'],
            'group_label' => $group['label'],
            'trigger_type' => (string) $route->trigger_type,
            'trigger_key' => (string) ($route->trigger_key ?? ''),
            'trigger_summary' => $this->triggerSummary($route),
            'entry_condition_summaries' => $entryConditions !== []
                ? [$this->entryConditionSummary($entryConditions)]
                : [],
            'assignment_count' => $activeBindings->count(),
            'is_enabled' => $activeBindings->isNotEmpty(),
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
     *     name_field_label: string,
     *     label: string|null,
     *     summary: string,
     *     condition_summaries: array<int, string>,
     *     decision_paths: array<int, array<string, mixed>>
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
                $fields = $this->authoring->has((string) $point->type)
                    ? $this->authoring->fields(
                        (string) $point->type,
                        is_array($point->definition) ? $point->definition : [],
                        $this->authoringContext($route, $point),
                    )
                    : [];

                return [
                    'key' => (string) $point->key,
                    'type' => (string) $point->type,
                    'module_key' => $this->pointModuleKey($point),
                    'type_label' => $this->pointTypeLabel($point->type),
                    'name_field_label' => $this->authoring->get((string) $point->type)?->nameFieldLabel
                        ?? 'Internal label',
                    'label' => $this->meaningfulPointLabel($point),
                    'summary' => $this->pointEditorSummary($point, $route),
                    'condition_summaries' => array_slice($summaries, 1),
                    'decision_paths' => $point->type === FlowRoutePointType::BranchEvaluate->value
                        ? $this->decisionPaths($point, $route)
                        : [],
                    'resources' => array_values(array_filter(
                        $fields,
                        fn (mixed $field): bool => is_array($field)
                            && ($field['type'] ?? null) === 'resource',
                    )),
                    'fields' => $fields,
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
        $primary = $point->type === FlowRoutePointType::BranchEvaluate->value
            ? $this->decisionSummary($point, $route)
            : ($this->authoring->has((string) $point->type)
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
            });

        return array_values(array_filter([
            $primary,
            ...$this->cancelConditionSummaries($point, $route),
        ]));
    }

    public function triggerSummary(FlowRoute $route): string
    {
        return match ($route->trigger_type) {
            FlowRoute::TRIGGER_CONTACT_STATUS => $this->contactStatusTriggerSummary($route),
            FlowRoute::TRIGGER_AUTOMATION_EVENT => 'When '.$this->humanAutomationEvent((string) $route->trigger_key).'.',
            FlowRoute::TRIGGER_MANUAL => 'Started manually.',
            default => Str::headline((string) $route->trigger_type).'.',
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function entryConditions(FlowRoute $route): array
    {
        $conditions = data_get($route->meta, 'definition.entry_conditions', []);

        if (! is_array($conditions)) {
            return [];
        }

        return array_values(array_filter($conditions, 'is_array'));
    }

    /** @param array<int, array<string, mixed>> $conditions */
    private function entryConditionSummary(array $conditions): string
    {
        $summary = $this->decisionConditionSummary($conditions);

        if (str_starts_with($summary, 'If ')) {
            $summary = Str::ucfirst(Str::after($summary, 'If '));
        }

        return rtrim($summary, '.').'.';
    }

    private function contactStatusTriggerSummary(FlowRoute $route): string
    {
        $summary = 'When a '.config('contacts.labels.singular', 'contact').' moves to '
            .$this->statusName((string) $route->trigger_key);
        $transition = data_get($route->meta, 'definition.transition', []);

        if (! is_array($transition)) {
            return $summary.'.';
        }

        $fromStatusKeys = $this->stringList($transition['from_contact_status_keys'] ?? []);
        $reasons = $this->stringList($transition['reasons'] ?? []);
        $sources = $this->stringList($transition['sources'] ?? []);

        if ($fromStatusKeys !== []) {
            $summary .= ' from '.$this->humanList(array_map(
                fn (string $statusKey): string => $this->statusName($statusKey),
                $fromStatusKeys,
            ));
        }

        if ($reasons !== []) {
            $summary .= ' after '.$this->humanList(array_map(
                fn (string $reason): string => Str::headline($reason),
                $reasons,
            ));
        } elseif ($sources !== []) {
            $summary .= ' through '.$this->humanList(array_map(
                fn (string $source): string => $this->humanTransitionSource($source),
                $sources,
            ));
        }

        return $summary.'.';
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
            $value,
        )));
    }

    /** @param array<int, string> $values */
    private function humanList(array $values): string
    {
        $values = array_values(array_filter($values, fn (string $value): bool => trim($value) !== ''));

        return match (count($values)) {
            0 => '',
            1 => $values[0],
            2 => $values[0].' or '.$values[1],
            default => implode(', ', array_slice($values, 0, -1)).', or '.$values[array_key_last($values)],
        };
    }

    private function humanTransitionSource(string $source): string
    {
        return match ($source) {
            'flow_routes' => 'another automatic Route',
            'crm' => 'a CRM update',
            'import' => 'an import',
            'workflow' => 'a workflow update',
            default => Str::headline($source),
        };
    }

    private function meaningfulPointLabel(FlowRoutePoint $point): ?string
    {
        if ($point->type === FlowRoutePointType::BranchEvaluate->value) {
            return null;
        }

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

        if ($point->type === FlowRoutePointType::BranchEvaluate->value) {
            return $this->decisionSummary($point, $route);
        }

        if ($this->authoring->has((string) $point->type)) {
            return $this->authoring->editorSummary(
                (string) $point->type,
                $definition,
                $this->authoringContext($route, $point),
            );
        }

        return (string) ($point->description ?: $point->name);
    }

    /** @return array<int, array<string, mixed>> */
    private function decisionPaths(FlowRoutePoint $point, FlowRoute $route): array
    {
        $definition = is_array($point->definition) ? $point->definition : [];
        $pointsByKey = $route->activeFlowRoutePoints()
            ->get(['key', 'name'])
            ->keyBy('key');
        $paths = [];

        foreach ($definition['branches'] ?? [] as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $conditions = is_array($branch['conditions'] ?? null)
                ? array_values(array_filter($branch['conditions'], 'is_array'))
                : [];
            $targetKey = trim((string) ($branch['target_flow_route_point_key'] ?? ''));

            $paths[] = [
                'kind' => 'match',
                'condition' => $this->decisionConditionSummary($conditions),
                'destination' => $this->decisionDestination($targetKey, $pointsByKey),
                'destination_key' => $targetKey !== '' ? $targetKey : null,
            ];
        }

        $defaultTarget = trim((string) ($definition['default_target_flow_route_point_key'] ?? ''));
        $paths[] = [
            'kind' => 'otherwise',
            'condition' => 'Otherwise',
            'destination' => $defaultTarget !== ''
                ? $this->decisionDestination($defaultTarget, $pointsByKey)
                : 'End this Route',
            'destination_key' => $defaultTarget !== '' ? $defaultTarget : null,
        ];

        return $paths;
    }

    private function decisionSummary(FlowRoutePoint $point, FlowRoute $route): string
    {
        $path = collect($this->decisionPaths($point, $route))->firstWhere('kind', 'match');
        $condition = is_array($path) ? trim((string) ($path['condition'] ?? '')) : '';
        $destination = is_array($path) ? trim((string) ($path['destination'] ?? '')) : '';

        if (str_starts_with($condition, 'If the contact status is ')
            && $destination !== ''
            && $destination !== 'End this Route'
        ) {
            return $destination.' only when '.Str::lower(Str::after($condition, 'If ')).'.';
        }

        if (str_starts_with($condition, 'If ')) {
            $condition = Str::lower(Str::after($condition, 'If '));
        }

        return $condition !== ''
            ? 'Decide whether '.$condition.'.'
            : 'Decide what should happen next.';
    }

    /**
     * @param array<int, array<string, mixed>> $conditions
     */
    private function decisionConditionSummary(array $conditions): string
    {
        $conditionsByPath = collect($conditions)
            ->filter(fn (array $condition): bool => is_string($condition['path'] ?? null))
            ->keyBy(fn (array $condition): string => (string) $condition['path']);
        $profileCondition = $conditionsByPath->get(
            'automation_event.payload.inbound_message.reply_profile_key',
        );
        $intentCondition = $conditionsByPath->get(
            'automation_event.payload.inbound_message.reply_intent_key',
        );

        if (is_array($profileCondition) && is_array($intentCondition)) {
            $profileKeys = $this->conditionValues($profileCondition);
            $intentKeys = $this->conditionValues($intentCondition);
            $outcomes = [];

            foreach ($profileKeys as $profileKey) {
                $profile = $this->replyProfiles->find($profileKey);

                foreach ($intentKeys as $intentKey) {
                    $intent = $profile !== null
                        ? collect($profile->intents)->firstWhere('key', $intentKey)
                        : null;
                    $outcomes[] = ($profile?->label ?? Str::headline($profileKey))
                        .' — '.(is_array($intent)
                            ? (string) ($intent['label'] ?? Str::headline($intentKey))
                            : Str::headline($intentKey));
                }
            }

            $summary = 'If the reply outcome is '.$this->humanList($outcomes);
            $channelCondition = $conditionsByPath->get(
                'automation_event.payload.inbound_message.channel',
            );

            if (is_array($channelCondition)) {
                $summary .= ' by '.$this->humanList(array_map(
                    fn (string $channel): string => Str::upper($channel),
                    $this->conditionValues($channelCondition),
                ));
            }

            return $summary;
        }

        if (count($conditions) === 1) {
            $condition = $conditions[0];
            $values = $this->conditionValues($condition);

            if (($condition['source'] ?? null) === 'contact_status'
                && ($condition['path'] ?? null) === 'key') {
                return 'If the contact status is '.$this->humanList(array_map(
                    fn (string $statusKey): string => $this->statusName($statusKey),
                    $values,
                ));
            }

            if (($condition['source'] ?? null) === 'contact_tags') {
                return 'If the contact has the tag '.$this->humanList($values);
            }
        }

        $parts = collect($conditions)
            ->map(function (array $condition): string {
                $label = Str::of((string) ($condition['path'] ?? $condition['source'] ?? 'fact'))
                    ->afterLast('.')
                    ->headline()
                    ->lower()
                    ->toString();
                $operator = match ((string) ($condition['operator'] ?? 'equals')) {
                    'in', 'equals' => 'is',
                    'contains' => 'contains',
                    default => Str::headline((string) ($condition['operator'] ?? 'matches')),
                };

                return trim($label.' '.$operator.' '.$this->humanList($this->conditionValues($condition)));
            })
            ->filter()
            ->values()
            ->all();

        return $parts !== []
            ? 'If '.implode(' and ', $parts)
            : 'If the configured conditions match';
    }

    /** @param array<string, mixed> $condition @return array<int, string> */
    private function conditionValues(array $condition): array
    {
        $values = is_array($condition['values'] ?? null)
            ? $condition['values']
            : [$condition['value'] ?? null];

        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== ''
                ? trim((string) $value)
                : null,
            $values,
        )));
    }

    private function decisionDestination(string $targetKey, Collection $pointsByKey): string
    {
        $target = $pointsByKey->get($targetKey);

        if ($target instanceof FlowRoutePoint) {
            $name = trim((string) $target->name);

            return $name !== '' ? $name : Str::headline($targetKey);
        }

        return $targetKey !== '' ? Str::headline($targetKey) : 'End this Route';
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
            'contact.created' => 'a contact is added manually',
            'contact.imported' => 'a contact is imported',
            'contact.tag_added' => 'a tag is added to a contact',
            'form.submitted' => 'someone submits a form',
            'appointment.scheduled' => 'an appointment is scheduled',
            'appointment.confirmed' => 'an appointment is confirmed',
            'appointment.rescheduled' => 'an appointment is rescheduled',
            'appointment.canceled' => 'an appointment is canceled',
            'appointment.completed' => 'an appointment is completed',
            'appointment.no_show' => 'someone misses an appointment',
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
            'contact' => 'core',
            'form' => 'forms',
            'appointment' => 'scheduling',
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