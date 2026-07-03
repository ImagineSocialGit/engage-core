<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Points\PointPresetDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePointPresetDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetSyncResult;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\Point;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncFlowRoutePresetsAction
{
    public function handle(
        ?string $presetKey = null,
        bool $force = false,
    ): FlowRoutePresetSyncResult {
        $presetKey = $this->normalizePresetKey($presetKey);

        $result = new FlowRoutePresetSyncResult();

        if ($presetKey === null) {
            $result->warn('No preset package key was provided and config[presets.default_package] is empty.');

            return $result;
        }

        $preset = config("presets.presets.{$presetKey}");

        if (! is_array($preset)) {
            $result->error("Preset package [{$presetKey}] does not exist.");

            return $result;
        }

        $flowRouteDefinitions = $this->flowRouteDefinitions(
            presetKey: $presetKey,
            result: $result,
        );

        foreach ($flowRouteDefinitions as $index => $flowRouteDefinition) {
            try {
                $definition = FlowRoutePresetDefinition::fromArray($presetKey, $flowRouteDefinition);
            } catch (Throwable $exception) {
                $result->error("Preset package [{$presetKey}] FlowRoute at index [{$index}] is invalid: {$exception->getMessage()}");

                continue;
            }

            $this->syncFlowRoutePreset($definition, $result, $force);
        }

        return $result;
    }

    private function normalizePresetKey(?string $presetKey): ?string
    {
        if (is_string($presetKey) && trim($presetKey) !== '') {
            return trim($presetKey);
        }

        $clientPreset = config('client.preset');

        if (is_string($clientPreset) && trim($clientPreset) !== '') {
            return trim($clientPreset);
        }

        $defaultPreset = config('presets.default');

        if (is_string($defaultPreset) && trim($defaultPreset) !== '') {
            return trim($defaultPreset);
        }

        $presetKeys = array_keys(config('presets.presets', []));

        foreach ($presetKeys as $key) {
            if (is_string($key) && trim($key) !== '') {
                return trim($key);
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flowRouteDefinitions(
        string $presetKey,
        FlowRoutePresetSyncResult $result,
    ): array {
        $groupKeys = config("presets.presets.{$presetKey}.flow_routes.groups", []);

        if (! is_array($groupKeys) || $groupKeys === []) {
            return [];
        }

        $definitions = [];

        foreach ($this->normalizeStringList($groupKeys) as $groupKey) {
            $flowRouteKeys = config("presets.flow-routes.groups.{$groupKey}");

            if (! is_array($flowRouteKeys)) {
                $result->error("FlowRoute preset group [{$groupKey}] does not exist.");

                continue;
            }

            foreach ($this->normalizeStringList($flowRouteKeys) as $flowRouteKey) {
                $definition = config("presets.flow-routes.definitions.{$flowRouteKey}");

                if (! is_array($definition)) {
                    $result->error("FlowRoute preset definition [{$flowRouteKey}] does not exist.");

                    continue;
                }

                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param array<mixed> $values
     * @return array<int, string>
     */
    private function normalizeStringList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        ))));
    }

    private function syncFlowRoutePreset(
        FlowRoutePresetDefinition $definition,
        FlowRoutePresetSyncResult $result,
        bool $force,
    ): void {
        DB::transaction(function () use ($definition, $result, $force) {
            $contactStatus = null;

            if ($definition->contactStatusKey !== null) {
                $contactStatus = ContactStatus::query()
                    ->where('key', $definition->contactStatusKey)
                    ->first();

                if (! $contactStatus) {
                    $result->warn("FlowRoute preset [{$definition->key}] skipped because ContactStatus [{$definition->contactStatusKey}] does not exist.");

                    return;
                }
            }

            $flowRoute = FlowRoute::query()->firstOrNew([
                'key' => $definition->key,
                'version' => $definition->version,
            ]);

            $flowRouteWasRecentlyCreated = ! $flowRoute->exists;

            if ($flowRoute->exists && $flowRoute->is_customized && ! $force) {
                $result->recordSkipped('flow_routes');
            } else {
                $flowRoute->forceFill([
                    'key' => $definition->key,
                    'contact_status_id' => $contactStatus?->getKey(),
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'version' => $definition->version,
                    'trigger_type' => $definition->triggerType(),
                    'trigger_key' => $definition->triggerKey(),
                    'is_active' => $definition->isActive,
                    'source_version' => $definition->sourceVersion,
                    'is_customized' => $force ? false : (bool) $flowRoute->is_customized,
                    'customized_at' => $force ? null : $flowRoute->customized_at,
                    'meta' => array_replace_recursive($flowRoute->meta ?? [], [
                        'preset' => [
                            'client_preset_key' => $definition->presetKey,
                            'flow_route_key' => $definition->key,
                            'contact_status_key' => $definition->contactStatusKey,
                            'trigger' => $definition->trigger,
                        ],
                        'definition' => $definition->meta,
                    ]),
                ])->save();

                $result->{$flowRouteWasRecentlyCreated ? 'recordCreated' : 'recordUpdated'}('flow_routes');
            }

            $pointsByKey = [];

            foreach ($definition->points as $pointDefinition) {
                $point = $this->syncPoint($definition, $pointDefinition, $result, $force);

                if ($point instanceof Point) {
                    $pointsByKey[$pointDefinition->key] = $point;
                }
            }

            $flowRoutePointsByKey = [];

            foreach ($definition->flowRoutePoints as $flowRoutePointDefinition) {
                $point = $pointsByKey[$flowRoutePointDefinition->pointKey]
                    ?? Point::query()->where('key', $flowRoutePointDefinition->pointKey)->first();

                if (! $point) {
                    $result->warn("FlowRoute preset [{$definition->key}] route point skipped because Point [{$flowRoutePointDefinition->pointKey}] does not exist.");

                    continue;
                }

                $flowRoutePoint = $this->syncFlowRoutePoint(
                    flowRoute: $flowRoute,
                    point: $point,
                    routeDefinition: $definition,
                    pointDefinition: $flowRoutePointDefinition,
                    result: $result,
                    force: $force,
                );

                if ($flowRoutePoint instanceof FlowRoutePoint) {
                    $flowRoutePointsByKey[$flowRoutePointDefinition->key] = $flowRoutePoint;
                }
            }

            $this->syncNextFlowRoutePoints(
                flowRoutePointsByKey: $flowRoutePointsByKey,
                pointDefinitions: $definition->flowRoutePoints,
                result: $result,
            );
        });
    }

    private function syncPoint(
        FlowRoutePresetDefinition $routeDefinition,
        PointPresetDefinition $definition,
        FlowRoutePresetSyncResult $result,
        bool $force,
    ): ?Point {
        $point = Point::query()->firstOrNew([
            'key' => $definition->key,
        ]);

        $wasRecentlyCreated = ! $point->exists;

        if ($point->exists && $point->is_customized && ! $force) {
            $result->recordSkipped('points');

            return $point;
        }

        $point->forceFill([
            'key' => $definition->key,
            'type' => $definition->type,
            'name' => $definition->name,
            'description' => $definition->description,
            'default_definition' => $definition->defaultDefinition,
            'default_settings' => $definition->defaultSettings,
            'is_active' => $definition->isActive,
            'source_version' => $definition->sourceVersion,
            'is_customized' => $force ? false : (bool) $point->is_customized,
            'customized_at' => $force ? null : $point->customized_at,
            'meta' => array_replace_recursive($point->meta ?? [], [
                'preset' => [
                    'client_preset_key' => $routeDefinition->presetKey,
                    'flow_route_key' => $routeDefinition->key,
                    'point_key' => $definition->key,
                ],
                'definition' => $definition->meta,
            ]),
        ])->save();

        $result->{$wasRecentlyCreated ? 'recordCreated' : 'recordUpdated'}('points');

        return $point;
    }

    private function syncFlowRoutePoint(
        FlowRoute $flowRoute,
        Point $point,
        FlowRoutePresetDefinition $routeDefinition,
        FlowRoutePointPresetDefinition $pointDefinition,
        FlowRoutePresetSyncResult $result,
        bool $force,
    ): ?FlowRoutePoint {
        $flowRoutePoint = FlowRoutePoint::query()->firstOrNew([
            'flow_route_id' => $flowRoute->getKey(),
            'key' => $pointDefinition->key,
        ]);

        $wasRecentlyCreated = ! $flowRoutePoint->exists;

        if ($flowRoutePoint->exists && $flowRoutePoint->is_customized && ! $force) {
            $result->recordSkipped('flow_route_points');

            return $flowRoutePoint;
        }

        $flowRoutePoint->forceFill([
            'flow_route_id' => $flowRoute->getKey(),
            'point_id' => $point->getKey(),
            'key' => $pointDefinition->key,
            'sort_order' => $pointDefinition->sortOrder,
            'is_start' => $pointDefinition->isStart,
            'is_active' => $pointDefinition->isActive,
            'next_flow_route_point_id' => null,
            'definition' => array_replace_recursive($pointDefinition->definition, [
                'conditions' => $pointDefinition->conditions,
            ]),
            'settings' => $pointDefinition->settings,
            'cancel_conditions' => $pointDefinition->cancelConditions,
            'source_version' => $pointDefinition->sourceVersion,
            'is_customized' => $force ? false : (bool) $flowRoutePoint->is_customized,
            'customized_at' => $force ? null : $flowRoutePoint->customized_at,
            'meta' => array_replace_recursive($flowRoutePoint->meta ?? [], [
                'preset' => [
                    'client_preset_key' => $routeDefinition->presetKey,
                    'flow_route_key' => $routeDefinition->key,
                    'flow_route_point_key' => $pointDefinition->key,
                    'point_key' => $pointDefinition->pointKey,
                    'sort_order' => $pointDefinition->sortOrder,
                    'next_point_key' => $pointDefinition->nextPointKey,
                ],
                'definition' => $pointDefinition->meta,
            ]),
        ])->save();

        $result->{$wasRecentlyCreated ? 'recordCreated' : 'recordUpdated'}('flow_route_points');

        return $flowRoutePoint;
    }

    /**
     * @param array<string, FlowRoutePoint> $flowRoutePointsByKey
     * @param array<int, FlowRoutePointPresetDefinition> $pointDefinitions
     */
    private function syncNextFlowRoutePoints(
        array $flowRoutePointsByKey,
        array $pointDefinitions,
        FlowRoutePresetSyncResult $result,
    ): void {
        foreach ($pointDefinitions as $pointDefinition) {
            if ($pointDefinition->nextPointKey === null) {
                continue;
            }

            $flowRoutePoint = $flowRoutePointsByKey[$pointDefinition->key] ?? null;
            $nextFlowRoutePoint = $flowRoutePointsByKey[$pointDefinition->nextPointKey] ?? null;

            if (! $flowRoutePoint instanceof FlowRoutePoint) {
                continue;
            }

            if (! $nextFlowRoutePoint instanceof FlowRoutePoint) {
                $result->warn("FlowRoutePoint [{$pointDefinition->key}] references missing next point [{$pointDefinition->nextPointKey}].");

                continue;
            }

            if ((int) $flowRoutePoint->next_flow_route_point_id === (int) $nextFlowRoutePoint->getKey()) {
                continue;
            }

            $flowRoutePoint->forceFill([
                'next_flow_route_point_id' => $nextFlowRoutePoint->getKey(),
            ])->save();

            $result->recordUpdated('flow_route_points');
        }
    }
}