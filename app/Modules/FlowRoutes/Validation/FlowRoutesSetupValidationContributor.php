<?php

namespace App\Modules\FlowRoutes\Validation;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Points\BranchEvaluatePointDefinition;
use App\Modules\FlowRoutes\Data\Points\CancelCampaignPointDefinition;
use App\Modules\FlowRoutes\Data\Points\ChangeStatusPointDefinition;
use App\Modules\FlowRoutes\Data\Points\ConditionPointDefinition;
use App\Modules\FlowRoutes\Data\Points\CreateTaskPointDefinition;
use App\Modules\FlowRoutes\Data\Points\EnrollCampaignPointDefinition;
use App\Modules\FlowRoutes\Data\Points\EventWaitPointDefinition;
use App\Modules\FlowRoutes\Data\Points\SendMessagePointDefinition;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetDefinition;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use App\Support\Modules\ModuleManager;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Collection;
use Throwable;

class FlowRoutesSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'presets.flow-routes';
    private const MODULE = 'flow_routes';

    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilityRegistry,
        private readonly PointHandlerRegistry $pointHandlerRegistry,
        private readonly ModuleManager $moduleManager,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
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
        $presetKey = $this->selectedPresetKey();

        if ($presetKey === null) {
            return;
        }

        $package = config("presets.packages.{$presetKey}");

        if (! is_array($package)) {
            return;
        }

        $groups = $package['groups']['flow_routes'] ?? [];

        if (! is_array($groups)) {
            yield $this->error(
                code: 'flow_routes.selected_groups_invalid',
                message: "Preset package [{$presetKey}] groups.flow_routes must be an array.",
                path: "presets.packages.{$presetKey}.groups.flow_routes",
                context: ['preset_key' => $presetKey],
            );

            return;
        }

        $allGroups = config('presets.flow-routes.groups', []);
        $allDefinitions = config('presets.flow-routes.definitions', []);

        $allGroups = is_array($allGroups) ? $allGroups : [];
        $allDefinitions = is_array($allDefinitions) ? $allDefinitions : [];

        $seenRouteGroups = [];

        foreach ($groups as $groupIndex => $groupKey) {
            if (! $this->filledString($groupKey)) {
                yield $this->error(
                    code: 'flow_routes.selected_group_key_invalid',
                    message: 'Selected FlowRoute preset group keys must be non-empty strings.',
                    path: "presets.packages.{$presetKey}.groups.flow_routes.{$groupIndex}",
                    context: ['preset_key' => $presetKey],
                );

                continue;
            }

            $groupKey = trim($groupKey);
            $routeKeys = $allGroups[$groupKey] ?? null;

            if (! is_array($routeKeys)) {
                yield $this->error(
                    code: 'flow_routes.group_missing',
                    message: "Selected FlowRoute preset group [{$groupKey}] does not exist.",
                    path: "presets.flow-routes.groups.{$groupKey}",
                    context: [
                        'preset_key' => $presetKey,
                        'group_key' => $groupKey,
                    ],
                );

                continue;
            }

            foreach ($routeKeys as $routeIndex => $routeKey) {
                if (! $this->filledString($routeKey)) {
                    yield $this->error(
                        code: 'flow_routes.group_reference_invalid',
                        message: "FlowRoute preset group [{$groupKey}] contains an invalid route reference.",
                        path: "presets.flow-routes.groups.{$groupKey}.{$routeIndex}",
                        context: [
                            'preset_key' => $presetKey,
                            'group_key' => $groupKey,
                        ],
                    );

                    continue;
                }

                $routeKey = trim($routeKey);

                if (isset($seenRouteGroups[$routeKey]) && $seenRouteGroups[$routeKey] !== $groupKey) {
                    yield $this->error(
                        code: 'flow_routes.route_key_ambiguous_across_groups',
                        message: "FlowRoute key [{$routeKey}] is selected by multiple FlowRoute preset groups.",
                        path: "presets.flow-routes.groups.{$groupKey}.{$routeIndex}",
                        context: [
                            'preset_key' => $presetKey,
                            'route_key' => $routeKey,
                            'first_group_key' => $seenRouteGroups[$routeKey],
                            'second_group_key' => $groupKey,
                        ],
                    );
                } else {
                    $seenRouteGroups[$routeKey] = $groupKey;
                }

                $definition = $allDefinitions[$routeKey] ?? null;

                if (! is_array($definition)) {
                    yield $this->error(
                        code: 'flow_routes.definition_missing',
                        message: "FlowRoute preset definition [{$routeKey}] does not exist.",
                        path: "presets.flow-routes.definitions.{$routeKey}",
                        context: [
                            'preset_key' => $presetKey,
                            'group_key' => $groupKey,
                            'route_key' => $routeKey,
                        ],
                    );

                    continue;
                }

                yield from $this->validatePresetDefinition(
                    presetKey: $presetKey,
                    groupKey: $groupKey,
                    routeKey: $routeKey,
                    definition: $definition,
                );
            }
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
    ): iterable {
        $path = "presets.flow-routes.definitions.{$routeKey}";
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
        $parsed = match ($pointType) {
            FlowRoutePointType::Wait->value => WaitPointDefinition::from($definition, $settings),
            FlowRoutePointType::EventWait->value => EventWaitPointDefinition::from($definition, $settings),
            FlowRoutePointType::Condition->value => ConditionPointDefinition::from($definition, $settings),
            FlowRoutePointType::BranchEvaluate->value => BranchEvaluatePointDefinition::from($definition, $settings),
            FlowRoutePointType::ChangeStatus->value => ChangeStatusPointDefinition::from($definition, $settings),
            FlowRoutePointType::CreateTask->value => CreateTaskPointDefinition::from($definition, $settings),
            FlowRoutePointType::SendMessage->value => SendMessagePointDefinition::from($definition, $settings),
            FlowRoutePointType::EnrollCampaign->value => EnrollCampaignPointDefinition::from($definition, $settings),
            FlowRoutePointType::CancelCampaign->value => CancelCampaignPointDefinition::from($definition, $settings),
            default => null,
        };

        if ($parsed !== null && method_exists($parsed, 'isValid') && ! $parsed->isValid()) {
            yield $this->error(
                code: 'flow_routes.point_definition_invalid',
                message: "FlowRoute [{$routeKey}] point [{$pointKey}] has invalid [{$pointType}] definition [{$parsed->invalidReason}].",
                path: "{$path}.definition",
                context: $context + [
                    'point_key' => $pointKey,
                    'point_type' => $pointType,
                    'invalid_reason' => $parsed->invalidReason,
                ],
            );

            return;
        }

        if ($parsed instanceof CreateTaskPointDefinition
            && $parsed->taskTemplateKey !== null
            && ! $this->taskTemplateExists($parsed->taskTemplateKey)
        ) {
            yield $this->error(
                code: 'flow_routes.task_template_missing',
                message: "FlowRoute [{$routeKey}] point [{$pointKey}] references unavailable TaskTemplate [{$parsed->taskTemplateKey}].",
                path: "{$path}.definition.task_template_key",
                context: $context + [
                    'point_key' => $pointKey,
                    'task_template_key' => $parsed->taskTemplateKey,
                ],
            );
        }

        if ($parsed instanceof ChangeStatusPointDefinition && ! $this->contactStatusTargetExists($parsed)) {
            yield $this->error(
                code: 'flow_routes.contact_status_missing',
                message: "FlowRoute [{$routeKey}] point [{$pointKey}] references an unavailable ContactStatus target.",
                path: "{$path}.definition",
                context: $context + [
                    'point_key' => $pointKey,
                    'contact_status_id' => $parsed->contactStatusId,
                    'contact_status_key' => $parsed->contactStatusKey,
                ],
            );
        }

        if (($parsed instanceof EnrollCampaignPointDefinition || $parsed instanceof CancelCampaignPointDefinition)
            && $parsed->campaignKey !== null
            && ! $this->campaignExists($parsed->campaignKey)
        ) {
            yield $this->error(
                code: 'flow_routes.campaign_missing',
                message: "FlowRoute [{$routeKey}] point [{$pointKey}] references unavailable Campaign [{$parsed->campaignKey}].",
                path: "{$path}.definition.campaign_key",
                context: $context + [
                    'point_key' => $pointKey,
                    'campaign_key' => $parsed->campaignKey,
                ],
            );
        }

        if ($parsed instanceof SendMessagePointDefinition) {
            yield from $this->validateSendMessageReference(
                routeKey: $routeKey,
                pointKey: $pointKey,
                parsed: $parsed,
                path: "{$path}.definition",
                context: $context,
            );
        }

        if ($parsed instanceof BranchEvaluatePointDefinition) {
            foreach ($parsed->branches as $branchIndex => $branch) {
                $target = $this->nullableString($branch['target_flow_route_point_key'] ?? null);

                if ($target !== null && ! in_array($target, $routePointKeys, true)) {
                    yield $this->error(
                        code: 'flow_routes.branch_target_missing',
                        message: "FlowRoute [{$routeKey}] point [{$pointKey}] branch references missing route point [{$target}].",
                        path: "{$path}.definition.branches.{$branchIndex}.target_flow_route_point_key",
                        context: $context + [
                            'point_key' => $pointKey,
                            'target_flow_route_point_key' => $target,
                        ],
                    );
                }
            }

            if ($parsed->defaultTargetFlowRoutePointKey !== null
                && ! in_array($parsed->defaultTargetFlowRoutePointKey, $routePointKeys, true)
            ) {
                yield $this->error(
                    code: 'flow_routes.branch_default_target_missing',
                    message: "FlowRoute [{$routeKey}] point [{$pointKey}] references missing default branch target [{$parsed->defaultTargetFlowRoutePointKey}].",
                    path: "{$path}.definition.default_target_flow_route_point_key",
                    context: $context + [
                        'point_key' => $pointKey,
                        'target_flow_route_point_key' => $parsed->defaultTargetFlowRoutePointKey,
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateSendMessageReference(
        string $routeKey,
        string $pointKey,
        SendMessagePointDefinition $parsed,
        string $path,
        array $context,
    ): iterable {
        try {
            $definitions = $this->messageDefinitionResolver->resolve(
                channel: $parsed->channel,
                purpose: $parsed->purpose,
                scope: $parsed->scope,
            );
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'flow_routes.messaging_resolution_failed',
                message: "FlowRoute [{$routeKey}] point [{$pointKey}] could not resolve Messaging definitions: {$exception->getMessage()}",
                path: $path,
                context: $context + ['point_key' => $pointKey],
                meta: ['exception' => $exception::class],
            );

            return;
        }

        foreach ($parsed->dispatchKeys as $dispatchKey) {
            $found = collect($definitions)->contains(function (mixed $definition) use ($dispatchKey): bool {
                if (! is_array($definition)) {
                    return false;
                }

                $keys = $definition['dispatch_keys']
                    ?? $definition['dispatch_key']
                    ?? [];

                $keys = is_string($keys) ? [$keys] : $keys;

                return is_array($keys) && in_array($dispatchKey, $keys, true);
            });

            if ($found) {
                continue;
            }

            yield $this->error(
                code: 'flow_routes.messaging_definition_missing',
                message: "FlowRoute [{$routeKey}] point [{$pointKey}] cannot resolve Messaging dispatch key [{$dispatchKey}] for [{$parsed->channel}:{$parsed->purpose}:{$parsed->scope}].",
                path: "{$path}.dispatch_keys",
                context: $context + [
                    'point_key' => $pointKey,
                    'dispatch_key' => $dispatchKey,
                    'channel' => $parsed->channel,
                    'purpose' => $parsed->purpose,
                    'scope' => $parsed->scope,
                ],
            );
        }
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

    private function taskTemplateExists(string $key): bool
    {
        if (! $this->moduleAvailable('tasks')) {
            return false;
        }

        return TaskTemplate::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->exists();
    }

    private function campaignExists(string $key): bool
    {
        if (! $this->moduleAvailable('campaigns')) {
            return false;
        }

        return Campaign::query()
            ->where('key', $key)
            ->active()
            ->exists();
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

    private function contactStatusTargetExists(ChangeStatusPointDefinition $definition): bool
    {
        $query = ContactStatus::query()->active();

        if ($definition->contactStatusId !== null) {
            return $query->whereKey($definition->contactStatusId)->exists();
        }

        if ($definition->contactStatusKey !== null) {
            return $query->where('key', $definition->contactStatusKey)->exists();
        }

        return false;
    }

    private function selectedPresetKey(): ?string
    {
        foreach ([config('client.preset'), config('presets.default_package')] as $presetKey) {
            if ($this->filledString($presetKey)) {
                return trim($presetKey);
            }
        }

        return null;
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function nullableString(mixed $value): ?string
    {
        return $this->filledString($value) ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
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
