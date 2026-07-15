<?php

namespace App\Modules\FlowRoutes\Validation;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetDefinition;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use App\Support\Modules\ModuleManager;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Collection;
use Throwable;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;

class FlowRoutesSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'preset_composition.flow_routes';
    private const MODULE = 'flow_routes';

    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilityRegistry,
        private readonly AutomationPointDefinitionRegistry $pointDefinitionRegistry,
        private readonly PointHandlerRegistry $pointHandlerRegistry,
        private readonly ModuleManager $moduleManager,
        private readonly PresetCompositionResolver $compositionResolver,
        private readonly PresetPackageResolver $packageResolver,
    ) {}

    public function findings(): iterable
    {
        yield from $this->validateSelectedPresetDefinitions();
        yield from $this->validateRuntimeCapabilities();
        yield from $this->validateRuntimeRoutes();
        yield from $this->validateRunnableInstances();
        yield from $this->validateActiveTriggerBindings();
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateSelectedPresetDefinitions(): iterable
    {
        $presetKey = $this->packageResolver->resolvePresetKey();

        if ($presetKey === null) {
            return;
        }

        try {
            $resolved = $this->compositionResolver->resolve(
                presetKey: $presetKey,
                domain: PresetDomain::FlowRoutes,
            );
        } catch (Throwable) {
            return;
        }

        foreach ($resolved->definitions as $routeKey => $definition) {
            $groupKey = $resolved->definitionGroups[$routeKey][0] ?? 'selected';
            $source = $resolved->provenance[$routeKey]['source'] ?? self::SOURCE;
            $path = "{$source}.definitions.{$routeKey}";

            yield from $this->validatePresetDefinition(
                presetKey: $presetKey,
                groupKey: $groupKey,
                routeKey: $routeKey,
                definition: $definition,
                path: $path,
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @return iterable<int, SetupValidationFinding>
     */
    private function validatePresetDefinition(
        string $presetKey,
        string $groupKey,
        string $routeKey,
        array $definition,
        string $path,
    ): iterable {
        $context = [
            'preset_key' => $presetKey,
            'group_key' => $groupKey,
            'route_key' => $routeKey,
        ];

        $internalKey = $definition['key'] ?? null;

        if (! $this->filledString($internalKey)) {
            yield $this->error(
                code: 'flow_routes.definition_key_missing',
                message: "FlowRoute preset definition [{$routeKey}] is missing a non-empty [key].",
                path: "{$path}.key",
                context: $context,
            );
        } elseif (trim($internalKey) !== $routeKey) {
            yield $this->error(
                code: 'flow_routes.definition_key_mismatch',
                message: "FlowRoute preset definition [{$routeKey}] key must match its config key.",
                path: "{$path}.key",
                context: $context + ['definition_key' => trim($internalKey)],
            );
        }

        try {
            $preset = FlowRoutePresetDefinition::fromArray($presetKey, $definition);
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'flow_routes.definition_invalid',
                message: $exception->getMessage(),
                path: $path,
                context: $context,
                meta: ['exception' => $exception::class],
            );

            return;
        }

        if ($preset->triggerType() === FlowRoute::TRIGGER_CONTACT_STATUS
            && ! $this->contactStatusExists($preset->triggerKey())
        ) {
            yield $this->error(
                code: 'flow_routes.trigger_contact_status_missing',
                message: "FlowRoute [{$routeKey}] references unavailable ContactStatus [{$preset->triggerKey()}].",
                path: "{$path}.contact_status_key",
                context: $context + ['contact_status_key' => $preset->triggerKey()],
            );
        }

        foreach ($preset->points as $index => $point) {
            $pointPath = "{$path}.points.{$index}";

            if (! $point->isActive) {
                continue;
            }

            if (! $this->pointHandlerRegistry->has($point->type)) {
                yield $this->error(
                    code: 'flow_routes.point_handler_missing',
                    message: "Selected FlowRoute [{$routeKey}] point [{$point->key}] uses executable point type [{$point->type}] with no registered handler.",
                    path: "{$pointPath}.type",
                    context: $context + [
                        'point_key' => $point->key,
                        'point_type' => $point->type,
                    ],
                );
            }

            if ($point->capabilityKey !== null) {
                yield from $this->validatePresetCapability(
                    routeKey: $routeKey,
                    pointKey: $point->key,
                    pointType: $point->type,
                    capabilityKey: $point->capabilityKey,
                    path: "{$pointPath}.capability_key",
                    context: $context,
                );
            }

            yield from $this->validatePointDefinition(
                routeKey: $routeKey,
                pointKey: $point->key,
                pointType: $point->type,
                definition: $point->definition,
                settings: $point->settings,
                routePointKeys: array_map(
                    fn ($candidate): string => $candidate->key,
                    $preset->points,
                ),
                path: $pointPath,
                context: $context,
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validatePresetCapability(
        string $routeKey,
        string $pointKey,
        string $pointType,
        string $capabilityKey,
        string $path,
        array $context,
    ): iterable {
        $definitions = $this->capabilityDefinitions();
        $definition = $definitions[$capabilityKey] ?? null;

        if (! $definition instanceof AutomationCapabilityDefinition) {
            yield $this->error(
                code: 'flow_routes.capability_missing',
                message: "Selected FlowRoute [{$routeKey}] point [{$pointKey}] references undeclared capability [{$capabilityKey}].",
                path: $path,
                context: $context + [
                    'point_key' => $pointKey,
                    'capability_key' => $capabilityKey,
                ],
            );

            return;
        }

        if ($definition->pointType !== $pointType) {
            yield $this->error(
                code: 'flow_routes.capability_point_type_mismatch',
                message: "Capability [{$capabilityKey}] expects point type [{$definition->pointType}], not [{$pointType}].",
                path: $path,
                context: $context + [
                    'point_key' => $pointKey,
                    'capability_key' => $capabilityKey,
                    'point_type' => $pointType,
                    'capability_point_type' => $definition->pointType,
                ],
            );
        }

        foreach ($definition->requiredModules as $requiredModule) {
            if ($this->moduleAvailable($requiredModule)) {
                continue;
            }

            yield $this->error(
                code: 'flow_routes.capability_required_module_unavailable',
                message: "Capability [{$capabilityKey}] requires unavailable module [{$requiredModule}].",
                path: $path,
                context: $context + [
                    'point_key' => $pointKey,
                    'capability_key' => $capabilityKey,
                    'required_module' => $requiredModule,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     * @param array<int, string> $routePointKeys
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validatePointDefinition(
        string $routeKey,
        string $pointKey,
        string $pointType,
        array $definition,
        array $settings,
        array $routePointKeys,
        string $path,
        array $context,
    ): iterable {
        if (! $this->pointDefinitionRegistry->has($pointType)) {
            yield $this->error(
                code: 'flow_routes.point_definition_contributor_missing',
                message: "FlowRoute [{$routeKey}] point [{$pointKey}] uses point type [{$pointType}] with no registered point-definition contributor.",
                path: "{$path}.type",
                context: $context + [
                    'point_key' => $pointKey,
                    'point_type' => $pointType,
                ],
            );

            return;
        }

        yield from $this->pointDefinitionRegistry->validate(
            pointType: $pointType,
            definition: $definition,
            settings: $settings,
            context: new AutomationPointValidationContext(
                containerKey: $routeKey,
                pointKey: $pointKey,
                pointType: $pointType,
                path: $path,
                siblingKeys: $routePointKeys,
                context: $context,
                source: self::SOURCE,
                module: self::MODULE,
            ),
        );
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRuntimeCapabilities(): iterable
    {
        $definitions = $this->capabilityDefinitions();

        /** @var FlowRouteCapability $capability */
        foreach (FlowRouteCapability::query()->active()->orderBy('key')->get() as $capability) {
            $definition = $definitions[$capability->key] ?? null;

            if (! $definition instanceof AutomationCapabilityDefinition) {
                yield $this->warning(
                    code: 'flow_routes.runtime_capability_not_declared',
                    message: "Active DB-owned FlowRouteCapability [{$capability->key}] has no current registered capability definition.",
                    path: "flow_route_capabilities.{$capability->getKey()}",
                    context: ['capability_key' => $capability->key],
                );

                continue;
            }

            if ($definition->pointType !== $capability->point_type) {
                yield $this->error(
                    code: 'flow_routes.runtime_capability_point_type_mismatch',
                    message: "DB-owned FlowRouteCapability [{$capability->key}] point type [{$capability->point_type}] does not match registered definition [{$definition->pointType}].",
                    path: "flow_route_capabilities.{$capability->getKey()}.point_type",
                    context: ['capability_key' => $capability->key],
                );
            }

            if (! $this->pointHandlerRegistry->has($capability->point_type)) {
                yield $this->warning(
                    code: 'flow_routes.runtime_capability_handler_unavailable',
                    message: "Active capability [{$capability->key}] is dormant because point handler [{$capability->point_type}] is unavailable.",
                    path: "flow_route_capabilities.{$capability->getKey()}.point_type",
                    context: ['capability_key' => $capability->key],
                );
            }
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRuntimeRoutes(): iterable
    {
        $currentDuplicates = FlowRoute::query()
            ->currentVersion()
            ->get()
            ->groupBy('key')
            ->filter(fn (Collection $routes): bool => $routes->count() > 1);

        foreach ($currentDuplicates as $routeKey => $routes) {
            yield $this->error(
                code: 'flow_routes.multiple_current_versions',
                message: "Logical FlowRoute [{$routeKey}] has multiple current versions.",
                path: 'flow_routes',
                context: [
                    'route_key' => $routeKey,
                    'flow_route_ids' => $routes->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                ],
            );
        }

        /** @var FlowRoute $route */
        foreach (FlowRoute::query()->active()->with(['flowRoutePoints.capability'])->get() as $route) {
            $activePoints = $route->flowRoutePoints
                ->filter(fn (FlowRoutePoint $point): bool => $point->is_active)
                ->values();

            $startCount = $activePoints->where('is_start', true)->count();

            if ($activePoints->isEmpty()) {
                yield $this->error(
                    code: 'flow_routes.runtime_active_points_missing',
                    message: "Active FlowRoute [{$route->key}] has no executable active points.",
                    path: "flow_routes.{$route->getKey()}",
                    context: ['route_key' => $route->key],
                );

                continue;
            }

            if ($startCount !== 1) {
                yield $this->error(
                    code: 'flow_routes.runtime_start_point_count_invalid',
                    message: "Active FlowRoute [{$route->key}] must have exactly one executable start point; found [{$startCount}].",
                    path: "flow_routes.{$route->getKey()}.flow_route_points",
                    context: ['route_key' => $route->key],
                );
            }

            $activeIds = $activePoints->pluck('id')->map(fn ($id): int => (int) $id)->all();

            foreach ($activePoints as $flowRoutePoint) {
                if (! $this->pointHandlerRegistry->has($flowRoutePoint->type)) {
                    yield $this->error(
                        code: 'flow_routes.runtime_point_handler_missing',
                        message: "Active FlowRoute [{$route->key}] point [{$flowRoutePoint->key}] cannot execute because handler [{$flowRoutePoint->type}] is unavailable.",
                        path: "flow_route_points.{$flowRoutePoint->getKey()}.type",
                        context: [
                            'route_key' => $route->key,
                            'point_key' => $flowRoutePoint->key,
                            'point_type' => $flowRoutePoint->type,
                        ],
                    );
                }

                if ($flowRoutePoint->next_flow_route_point_id !== null
                    && ! in_array((int) $flowRoutePoint->next_flow_route_point_id, $activeIds, true)
                ) {
                    yield $this->error(
                        code: 'flow_routes.runtime_next_point_invalid',
                        message: "Active FlowRoute [{$route->key}] point [{$flowRoutePoint->key}] references a missing or inactive next route point.",
                        path: "flow_route_points.{$flowRoutePoint->getKey()}.next_flow_route_point_id",
                        context: [
                            'route_key' => $route->key,
                            'point_key' => $flowRoutePoint->key,
                        ],
                    );
                }

                if ($flowRoutePoint->flow_route_capability_id !== null) {
                    $capability = $flowRoutePoint->capability;

                    if (! $capability instanceof FlowRouteCapability || ! $capability->is_active) {
                        yield $this->error(
                            code: 'flow_routes.runtime_capability_missing_or_inactive',
                            message: "Active FlowRoute [{$route->key}] point [{$flowRoutePoint->key}] references a missing or inactive capability.",
                            path: "flow_route_points.{$flowRoutePoint->getKey()}.flow_route_capability_id",
                            context: [
                                'route_key' => $route->key,
                                'point_key' => $flowRoutePoint->key,
                            ],
                        );
                    } elseif ($capability->point_type !== $flowRoutePoint->type) {
                        yield $this->error(
                            code: 'flow_routes.runtime_capability_point_mismatch',
                            message: "Active FlowRoute [{$route->key}] point [{$flowRoutePoint->key}] capability [{$capability->key}] expects [{$capability->point_type}], not [{$flowRoutePoint->type}].",
                            path: "flow_route_points.{$flowRoutePoint->getKey()}.flow_route_capability_id",
                            context: [
                                'route_key' => $route->key,
                                'point_key' => $flowRoutePoint->key,
                                'capability_key' => $capability->key,
                            ],
                        );
                    }
                }
            }
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRunnableInstances(): iterable
    {
        /** @var ContactFlowRouteProgress $progress */
        foreach (ContactFlowRouteProgress::query()
            ->runnable()
            ->with(['flowRoute', 'currentFlowRoutePoint', 'plan'])
            ->get() as $progress
        ) {
            $route = $progress->flowRoute;
            $currentPoint = $progress->currentFlowRoutePoint;

            if (! $route instanceof FlowRoute) {
                yield $this->error(
                    code: 'flow_routes.runnable_progress_route_missing',
                    message: "Runnable FlowRoute progress [{$progress->getKey()}] references a missing route.",
                    path: "contact_flow_route_progress.{$progress->getKey()}.flow_route_id",
                    context: ['progress_id' => $progress->getKey()],
                );

                continue;
            }

            if (! $route->is_current_version) {
                yield $this->error(
                    code: 'flow_routes.runnable_progress_on_historical_version',
                    message: "Runnable FlowRoute progress [{$progress->getKey()}] remains pinned to historical route [{$route->key}] version [{$route->version}].",
                    path: "contact_flow_route_progress.{$progress->getKey()}.flow_route_id",
                    context: [
                        'progress_id' => $progress->getKey(),
                        'route_key' => $route->key,
                        'route_version' => $route->version,
                    ],
                );
            }

            if (! $currentPoint instanceof FlowRoutePoint
                || ! $currentPoint->is_active
                || (int) $currentPoint->flow_route_id !== (int) $progress->flow_route_id
            ) {
                yield $this->error(
                    code: 'flow_routes.runnable_progress_current_point_invalid',
                    message: "Runnable FlowRoute progress [{$progress->getKey()}] has no valid active current route point.",
                    path: "contact_flow_route_progress.{$progress->getKey()}.current_flow_route_point_id",
                    context: ['progress_id' => $progress->getKey()],
                );
            }

            if (! $progress->plan instanceof ContactFlowRoutePlan) {
                yield $this->error(
                    code: 'flow_routes.runnable_progress_plan_missing',
                    message: "Runnable FlowRoute progress [{$progress->getKey()}] has no active route plan.",
                    path: "contact_flow_route_progress.{$progress->getKey()}",
                    context: ['progress_id' => $progress->getKey()],
                );
            }
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateActiveTriggerBindings(): iterable
    {
        /** @var FlowRouteTriggerBinding $binding */
        foreach (FlowRouteTriggerBinding::query()->active()->with('flowRoute')->get() as $binding) {
            $route = $binding->flowRoute;

            if (! $route instanceof FlowRoute || ! $route->is_active || ! $route->is_current_version) {
                yield $this->error(
                    code: 'flow_routes.active_binding_route_unavailable',
                    message: "Active FlowRoute trigger binding [{$binding->getKey()}] references a missing, inactive, or historical route.",
                    path: "flow_route_trigger_bindings.{$binding->getKey()}.flow_route_id",
                    context: ['binding_id' => $binding->getKey()],
                );
            }

            if ($binding->trigger_type === FlowRoute::TRIGGER_CONTACT_STATUS
                && ! $this->contactStatusExists($binding->trigger_key)
            ) {
                yield $this->error(
                    code: 'flow_routes.active_binding_contact_status_missing',
                    message: "Active FlowRoute trigger binding [{$binding->getKey()}] references unavailable ContactStatus [{$binding->trigger_key}].",
                    path: "flow_route_trigger_bindings.{$binding->getKey()}.trigger_key",
                    context: ['binding_id' => $binding->getKey()],
                );
            }
        }
    }

    /**
     * @return array<string, AutomationCapabilityDefinition>
     */
    private function capabilityDefinitions(): array
    {
        try {
            $definitions = $this->capabilityRegistry->definitions();
        } catch (Throwable) {
            return [];
        }

        $indexed = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof AutomationCapabilityDefinition) {
                $indexed[$definition->key] = $definition;
            }
        }

        return $indexed;
    }

    private function moduleAvailable(string $moduleKey): bool
    {
        return in_array($moduleKey, $this->moduleManager->enabledKeysWithDependencies(), true);
    }

    private function contactStatusExists(?string $key): bool
    {
        if (! $this->filledString($key)) {
            return false;
        }

        return ContactStatus::query()
            ->where('key', $key)
            ->active()
            ->exists();
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
        array $meta = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warning(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}
