<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePointPresetDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetSyncResult;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncFlowRoutePresetsAction
{
    public function __construct(
        private readonly ReconcileFlowRouteProgressToCurrentVersionAction $reconcileFlowRouteProgressToCurrentVersion,
    ) {}

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

        $package = config("presets.packages.{$presetKey}");

        if (! is_array($package)) {
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

        $defaultPackage = config('presets.default_package');

        if (is_string($defaultPackage) && trim($defaultPackage) !== '') {
            return trim($defaultPackage);
        }

        $packageKeys = array_keys(config('presets.packages', []));

        foreach ($packageKeys as $key) {
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
        $groupKeys = config("presets.packages.{$presetKey}.groups.flow_routes", []);

        $groupKeys = $this->normalizeStringList($groupKeys);

        if ($groupKeys === []) {
            return [];
        }

        $definitions = [];

        foreach ($groupKeys as $groupKey) {
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
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

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
        if (! $this->validateCapabilityReferences($definition, $result)) {
            return;
        }

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
                if (! $flowRoute->is_current_version) {
                    $flowRoute->forceFill([
                        'is_current_version' => true,
                    ])->save();

                    $result->recordUpdated('flow_routes');
                } else {
                    $result->recordSkipped('flow_routes');
                }
            } else {
                $flowRoute->forceFill([
                    'key' => $definition->key,
                    'contact_status_id' => $contactStatus?->getKey(),
                    'owner_type' => $definition->ownerType,
                    'owner_id' => $definition->ownerId,
                    'owner_group' => $definition->ownerGroup,
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'version' => $definition->version,
                    'is_current_version' => true,
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

            $flowRoutePointsByKey = [];

            foreach ($definition->points as $pointDefinition) {
                $capability = $pointDefinition->capabilityKey !== null
                    ? FlowRouteCapability::query()
                        ->where('key', $pointDefinition->capabilityKey)
                        ->first()
                    : null;

                $flowRoutePoint = $this->syncFlowRoutePoint(
                    flowRoute: $flowRoute,
                    capability: $capability,
                    routeDefinition: $definition,
                    pointDefinition: $pointDefinition,
                    result: $result,
                    force: $force,
                );

                if ($flowRoutePoint instanceof FlowRoutePoint) {
                    $flowRoutePointsByKey[$pointDefinition->key] = $flowRoutePoint;
                }
            }

            $this->syncNextFlowRoutePoints(
                flowRoutePointsByKey: $flowRoutePointsByKey,
                pointDefinitions: $definition->points,
                result: $result,
            );

            $reconciledProgressCount = $this->reconcileFlowRouteProgressToCurrentVersion->handle($flowRoute);

            if ($reconciledProgressCount > 0) {
                $result->warn("FlowRoute [{$flowRoute->key}] reconciled [{$reconciledProgressCount}] active/waiting instance(s) to version [{$flowRoute->version}].");
            }

            $this->reconcileLogicalRouteVersions(
                currentFlowRoute: $flowRoute,
                result: $result,
            );

            $this->syncDefaultTriggerBinding(
                flowRoute: $flowRoute,
                definition: $definition,
                result: $result,
                force: $force,
            );
        });
    }

    private function syncDefaultTriggerBinding(
        FlowRoute $flowRoute,
        FlowRoutePresetDefinition $definition,
        FlowRoutePresetSyncResult $result,
        bool $force,
    ): void {
        $triggerType = $definition->triggerType();
        $triggerKey = $definition->triggerKey();

        $this->deactivateObsoleteDefaultBindings(
            flowRoute: $flowRoute,
            triggerType: $triggerType,
            triggerKey: $triggerKey,
            shouldHaveDefaultBinding: $definition->shouldCreateDefaultBinding(),
            result: $result,
        );

        if (! $definition->shouldCreateDefaultBinding()) {
            return;
        }

        if (! $flowRoute->is_current_version || ! $flowRoute->is_active) {
            return;
        }

        $binding = FlowRouteTriggerBinding::query()->firstOrNew([
            'trigger_type' => $triggerType,
            'trigger_key' => $triggerKey,
            'flow_route_id' => $flowRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
        ]);

        $wasRecentlyCreated = ! $binding->exists;

        if ($binding->exists && $binding->is_active && ! $force) {
            $result->recordSkipped('flow_route_trigger_bindings');

            return;
        }

        $binding->forceFill([
            'trigger_type' => $triggerType,
            'trigger_key' => $triggerKey,
            'flow_route_id' => $flowRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => array_replace_recursive($binding->meta ?? [], [
                'preset' => [
                    'client_preset_key' => $definition->presetKey,
                    'flow_route_key' => $definition->key,
                    'flow_route_version' => $definition->version,
                    'trigger' => $definition->trigger,
                    'default_binding' => true,
                ],
            ]),
        ])->save();

        $result->{$wasRecentlyCreated ? 'recordCreated' : 'recordUpdated'}('flow_route_trigger_bindings');
    }

    private function reconcileLogicalRouteVersions(
        FlowRoute $currentFlowRoute,
        FlowRoutePresetSyncResult $result,
    ): void {
        $historicalVersions = FlowRoute::query()
            ->where('key', $currentFlowRoute->key)
            ->where('id', '!=', $currentFlowRoute->getKey())
            ->get();

        foreach ($historicalVersions as $historicalVersion) {
            $wasChanged = false;

            if ($historicalVersion->is_current_version) {
                $historicalVersion->is_current_version = false;
                $wasChanged = true;
            }

            if ($wasChanged) {
                $historicalVersion->save();
                $result->recordUpdated('flow_routes');
            }

            FlowRouteTriggerBinding::query()
                ->where('flow_route_id', $historicalVersion->getKey())
                ->where('is_active', true)
                ->get()
                ->each(function (FlowRouteTriggerBinding $binding) use ($result): void {
                    $binding->forceFill(['is_active' => false])->save();
                    $result->recordUpdated('flow_route_trigger_bindings');
                });
        }
    }

    private function deactivateObsoleteDefaultBindings(
        FlowRoute $flowRoute,
        string $triggerType,
        ?string $triggerKey,
        bool $shouldHaveDefaultBinding,
        FlowRoutePresetSyncResult $result,
    ): void {
        $bindings = FlowRouteTriggerBinding::query()
            ->where('flow_route_id', $flowRoute->getKey())
            ->whereNull('context_type')
            ->whereNull('context_id')
            ->where('is_active', true)
            ->get();

        foreach ($bindings as $binding) {
            $isPresetDefault = (bool) data_get($binding->meta, 'preset.default_binding', false);

            if (! $isPresetDefault) {
                continue;
            }

            $matchesCurrentTrigger = $binding->trigger_type === $triggerType
                && $binding->trigger_key === $triggerKey;

            if ($shouldHaveDefaultBinding && $matchesCurrentTrigger) {
                continue;
            }

            $binding->forceFill(['is_active' => false])->save();
            $result->recordUpdated('flow_route_trigger_bindings');
        }
    }

    private function validateCapabilityReferences(
        FlowRoutePresetDefinition $definition,
        FlowRoutePresetSyncResult $result,
    ): bool {
        $valid = true;

        foreach ($definition->points as $pointDefinition) {
            if ($pointDefinition->capabilityKey === null) {
                continue;
            }

            $capability = FlowRouteCapability::query()
                ->where('key', $pointDefinition->capabilityKey)
                ->first();

            if (! $capability instanceof FlowRouteCapability) {
                $result->error("FlowRoute preset [{$definition->key}] route point [{$pointDefinition->key}] references missing capability [{$pointDefinition->capabilityKey}].");
                $valid = false;

                continue;
            }

            if ($capability->point_type !== $pointDefinition->type) {
                $result->error("FlowRoute preset [{$definition->key}] route point [{$pointDefinition->key}] capability [{$pointDefinition->capabilityKey}] expects point type [{$capability->point_type}], not [{$pointDefinition->type}].");
                $valid = false;
            }
        }

        return $valid;
    }

    private function syncFlowRoutePoint(
        FlowRoute $flowRoute,
        ?FlowRouteCapability $capability,
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
            'flow_route_capability_id' => $capability?->getKey(),
            'key' => $pointDefinition->key,
            'type' => $pointDefinition->type,
            'name' => $pointDefinition->name,
            'description' => $pointDefinition->description,
            'sort_order' => $pointDefinition->sortOrder,
            'is_start' => $pointDefinition->isStart,
            'is_active' => $pointDefinition->isActive,
            'next_flow_route_point_id' => null,
            'definition' => $pointDefinition->definition,
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
                    'capability_key' => $pointDefinition->capabilityKey,
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
