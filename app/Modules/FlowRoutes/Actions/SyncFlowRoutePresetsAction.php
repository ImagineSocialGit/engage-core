<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePointPresetDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetSyncResult;
use App\Modules\FlowRoutes\Data\Points\PointPresetDefinition;
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
        $presetKey = $presetKey ?: (string) config('presets.default');

        $result = new FlowRoutePresetSyncResult();

        if (trim($presetKey) === '') {
            $result->warn('No preset key was provided and config[presets.default] is empty.');

            return $result;
        }

        $preset = config("presets.presets.{$presetKey}");

        if (! is_array($preset)) {
            $result->error("Preset [{$presetKey}] does not exist.");

            return $result;
        }

        $flowRoutePresets = $preset['flow_routes'] ?? [];

        if (! is_array($flowRoutePresets) || $flowRoutePresets === []) {
            return $result;
        }

        foreach (array_values($flowRoutePresets) as $index => $flowRoutePreset) {
            if (! is_array($flowRoutePreset)) {
                $result->warn("Preset [{$presetKey}] FlowRoute at index [{$index}] is not an array.");

                continue;
            }

            try {
                $definition = FlowRoutePresetDefinition::fromArray($presetKey, $flowRoutePreset);
            } catch (Throwable $exception) {
                $result->error("Preset [{$presetKey}] FlowRoute at index [{$index}] is invalid: {$exception->getMessage()}");

                continue;
            }

            $this->syncFlowRoutePreset($definition, $result, $force);
        }

        return $result;
    }

    private function syncFlowRoutePreset(
        FlowRoutePresetDefinition $definition,
        FlowRoutePresetSyncResult $result,
        bool $force,
    ): void {
        DB::transaction(function () use ($definition, $result, $force) {
            $contactStatus = ContactStatus::query()
                ->where('key', $definition->contactStatusKey)
                ->first();

            if (! $contactStatus) {
                $result->warn("FlowRoute preset [{$definition->key}] skipped because ContactStatus [{$definition->contactStatusKey}] does not exist.");

                return;
            }

            $flowRoute = FlowRoute::query()->firstOrNew([
                'contact_status_id' => $contactStatus->getKey(),
                'version' => $definition->version,
            ]);

            $flowRouteWasRecentlyCreated = ! $flowRoute->exists;

            if ($flowRoute->exists && $flowRoute->is_customized && ! $force) {
                $result->recordSkipped('flow_routes');
            } else {
                $flowRoute->forceFill([
                    'contact_status_id' => $contactStatus->getKey(),
                    'name' => $definition->name,
                    'version' => $definition->version,
                    'is_active' => $definition->isActive,
                    'preset_key' => $definition->presetKey.'.'.$definition->key,
                    'source_version' => $definition->sourceVersion,
                    'is_customized' => $force ? false : (bool) $flowRoute->is_customized,
                    'customized_at' => $force ? null : $flowRoute->customized_at,
                    'meta' => array_replace_recursive($flowRoute->meta ?? [], [
                        'preset' => [
                            'client_preset_key' => $definition->presetKey,
                            'flow_route_key' => $definition->key,
                            'contact_status_key' => $definition->contactStatusKey,
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

            foreach ($definition->flowRoutePoints as $flowRoutePointDefinition) {
                $point = $pointsByKey[$flowRoutePointDefinition->pointKey]
                    ?? Point::query()->where('key', $flowRoutePointDefinition->pointKey)->first();

                if (! $point) {
                    $result->warn("FlowRoute preset [{$definition->key}] route point skipped because Point [{$flowRoutePointDefinition->pointKey}] does not exist.");

                    continue;
                }

                $this->syncFlowRoutePoint(
                    flowRoute: $flowRoute,
                    point: $point,
                    routeDefinition: $definition,
                    pointDefinition: $flowRoutePointDefinition,
                    result: $result,
                    force: $force,
                );
            }
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
            'preset_key' => $routeDefinition->presetKey.'.points.'.$definition->key,
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
    ): void {
        $flowRoutePoint = FlowRoutePoint::query()->firstOrNew([
            'flow_route_id' => $flowRoute->getKey(),
            'sort_order' => $pointDefinition->sortOrder,
        ]);

        $wasRecentlyCreated = ! $flowRoutePoint->exists;

        if ($flowRoutePoint->exists && $flowRoutePoint->is_customized && ! $force) {
            $result->recordSkipped('flow_route_points');

            return;
        }

        $flowRoutePoint->forceFill([
            'flow_route_id' => $flowRoute->getKey(),
            'point_id' => $point->getKey(),
            'sort_order' => $pointDefinition->sortOrder,
            'is_active' => $pointDefinition->isActive,
            'definition' => $pointDefinition->definition,
            'settings' => $pointDefinition->settings,
            'cancel_conditions' => $pointDefinition->cancelConditions,
            'preset_key' => $routeDefinition->presetKey.'.'.$routeDefinition->key.'.'.$pointDefinition->pointKey.'.'.$pointDefinition->sortOrder,
            'source_version' => $pointDefinition->sourceVersion,
            'is_customized' => $force ? false : (bool) $flowRoutePoint->is_customized,
            'customized_at' => $force ? null : $flowRoutePoint->customized_at,
            'meta' => array_replace_recursive($flowRoutePoint->meta ?? [], [
                'preset' => [
                    'client_preset_key' => $routeDefinition->presetKey,
                    'flow_route_key' => $routeDefinition->key,
                    'point_key' => $pointDefinition->pointKey,
                    'sort_order' => $pointDefinition->sortOrder,
                ],
                'definition' => $pointDefinition->meta,
            ]),
        ])->save();

        $result->{$wasRecentlyCreated ? 'recordCreated' : 'recordUpdated'}('flow_route_points');
    }
}