<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use Illuminate\Support\Collection;

class FlowRouteEditorCatalog
{
    public function __construct(
        private readonly AutomationPointAuthoringRegistry $authoring,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function availableCapabilities(FlowRoute $route): Collection
    {
        if ($route->isAutomaticBehavior() && $route->activeFlowRoutePoints()->exists()) {
            return collect();
        }

        return FlowRouteCapability::query()
            ->active()
            ->whereIn('point_type', $this->authoring->registeredTypes())
            ->orderBy('module_key')
            ->orderBy('name')
            ->get()
            ->filter(function (FlowRouteCapability $capability) use ($route): bool {
                if (data_get($capability->meta, 'runtime.handler_available_at_sync', true) === false) {
                    return false;
                }

                return $this->authoring->available(
                    (string) $capability->point_type,
                    $this->context($route, capability: $capability),
                );
            })
            ->map(function (FlowRouteCapability $capability) use ($route): array {
                $pointType = (string) $capability->point_type;
                $definition = $this->authoring->get($pointType);
                $context = $this->context($route, capability: $capability);

                return [
                    'id' => (int) $capability->getKey(),
                    'key' => (string) $capability->key,
                    'module_key' => (string) $capability->module_key,
                    'point_type' => $pointType,
                    'name' => $this->stepLanguage($definition?->name ?? (string) $capability->name),
                    'description' => $this->stepLanguage(
                        $definition?->description ?? (string) ($capability->description ?? ''),
                    ),
                    'tip' => $this->stepLanguage($definition?->tip ?? ''),
                    'use_cases' => array_map(
                        fn (mixed $useCase): string => $this->stepLanguage((string) $useCase),
                        $definition?->useCases ?? [],
                    ),
                    'fields' => $this->presentFields(
                        $this->authoring->fields($pointType, [], $context),
                    ),
                ];
            })
            ->values();
    }

    /**
     * Retained as a compatibility seam for current controllers/views while point-specific
     * options now travel with each contributor-owned field definition.
     *
     * @return array<string, mixed>
     */
    public function editorOptions(): array
    {
        return [];
    }

    private function stepLanguage(string $copy): string
    {
        return (string) preg_replace_callback(
            '/\b(?:Point|Points|point|points)\b/',
            static fn (array $match): string => match ($match[0]) {
                'Point' => 'Step',
                'Points' => 'Steps',
                'points' => 'steps',
                default => 'step',
            },
            $copy,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function presentFields(array $fields): array
    {
        return array_map(function (array $field): array {
            foreach (['label', 'help', 'placeholder', 'description'] as $copyKey) {
                if (is_string($field[$copyKey] ?? null)) {
                    $field[$copyKey] = $this->stepLanguage($field[$copyKey]);
                }
            }

            if (is_array($field['options'] ?? null)) {
                $field['options'] = array_map(function (mixed $option): mixed {
                    if (! is_array($option) || ! is_string($option['label'] ?? null)) {
                        return $option;
                    }

                    $option['label'] = $this->stepLanguage($option['label']);

                    return $option;
                }, $field['options']);
            }

            return $field;
        }, $fields);
    }

    private function context(
        FlowRoute $route,
        ?FlowRouteCapability $capability = null,
    ): AutomationPointAuthoringContext {
        return new AutomationPointAuthoringContext(
            existingPointTypes: $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->pluck('type')
                ->map(fn (mixed $type): string => (string) $type)
                ->all(),
            container: $route,
            capability: $capability,
        );
    }
}