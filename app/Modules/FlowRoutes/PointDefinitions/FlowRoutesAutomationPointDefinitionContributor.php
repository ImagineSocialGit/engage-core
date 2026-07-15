<?php

namespace App\Modules\FlowRoutes\PointDefinitions;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Points\BranchEvaluatePointDefinition;
use App\Modules\FlowRoutes\Data\Points\ChangeStatusPointDefinition;
use App\Modules\FlowRoutes\Data\Points\ConditionPointDefinition;
use App\Modules\FlowRoutes\Data\Points\EventWaitPointDefinition;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class FlowRoutesAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    private const OUTCOMES = [
        'completed',
        'skipped',
        'blocked',
        'failed',
    ];

    public function definitions(): iterable
    {
        $open = $this->openSchema();

        yield new AutomationPointDefinition(
            pointType: 'noop',
            schema: ConfigSchema::object([]),
        );

        yield new AutomationPointDefinition(
            pointType: 'wait',
            schema: $this->waitSchema(),
        );

        yield new AutomationPointDefinition(
            pointType: 'event_wait',
            schema: ConfigSchema::object([
                'event_key' => ConfigField::required(ConfigSchema::string()),
                'correlation' => ConfigField::defaulted($open, []),
                'meta' => ConfigField::defaulted($open, []),
            ]),
        );

        yield new AutomationPointDefinition(
            pointType: 'condition',
            schema: ConfigSchema::object([
                'conditions' => ConfigField::required(ConfigSchema::listOf($open)),
                'mode' => ConfigField::defaulted(
                    ConfigSchema::string(allowedValues: ConditionPointDefinition::MODES),
                    ConditionPointDefinition::MODE_ALL,
                ),
                'on_pass' => ConfigField::defaulted(
                    ConfigSchema::string(allowedValues: self::OUTCOMES),
                    'completed',
                ),
                'on_fail' => ConfigField::defaulted(
                    ConfigSchema::string(allowedValues: self::OUTCOMES),
                    'blocked',
                ),
            ]),
        );

        yield new AutomationPointDefinition(
            pointType: 'branch_evaluate',
            schema: ConfigSchema::object([
                'branches' => ConfigField::optional(ConfigSchema::listOf($open)),
                'mode' => ConfigField::defaulted(
                    ConfigSchema::string(allowedValues: ConditionPointDefinition::MODES),
                    ConditionPointDefinition::MODE_ALL,
                ),
                'on_no_match' => ConfigField::defaulted(
                    ConfigSchema::string(
                        allowedValues: BranchEvaluatePointDefinition::ON_NO_MATCH_ACTIONS,
                    ),
                    BranchEvaluatePointDefinition::ON_NO_MATCH_BLOCKED,
                ),
                'default_target_flow_route_point_key' => ConfigField::optional(
                    ConfigSchema::string(nullable: true),
                ),
            ], atLeastOne: [
                ['branches', 'default_target_flow_route_point_key'],
            ]),
        );

        yield new AutomationPointDefinition(
            pointType: 'change_status',
            schema: ConfigSchema::object([
                'contact_status_id' => ConfigField::optional(ConfigSchema::integer()),
                'contact_status_key' => ConfigField::optional(
                    ConfigSchema::string(),
                    referenceTarget: 'contact_statuses',
                ),
                'reason' => ConfigField::optional(ConfigSchema::string()),
                'force' => ConfigField::defaulted(ConfigSchema::boolean(), false),
                'on_same_status' => ConfigField::defaulted(
                    ConfigSchema::string(allowedValues: self::OUTCOMES),
                    'skipped',
                ),
                'meta' => ConfigField::defaulted($open, []),
            ], atLeastOne: [
                ['contact_status_id', 'contact_status_key'],
            ], atMostOne: [
                ['contact_status_id', 'contact_status_key'],
            ]),
        );
    }

    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        $parsed = match ($pointType) {
            'wait' => WaitPointDefinition::from($definition, $settings),
            'event_wait' => EventWaitPointDefinition::from($definition, $settings),
            'condition' => ConditionPointDefinition::from($definition, $settings),
            'branch_evaluate' => BranchEvaluatePointDefinition::from($definition, $settings),
            'change_status' => ChangeStatusPointDefinition::from($definition, $settings),
            default => null,
        };

        if ($parsed !== null
            && method_exists($parsed, 'isValid')
            && ! $parsed->isValid()
        ) {
            yield $context->error(
                code: 'flow_routes.point_definition_invalid',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] has invalid [{$pointType}] definition [{$parsed->invalidReason}].",
                path: "{$context->path}.definition",
                context: [
                    'point_key' => $context->pointKey,
                    'point_type' => $pointType,
                    'invalid_reason' => $parsed->invalidReason,
                ],
            );

            return;
        }

        if ($parsed instanceof ChangeStatusPointDefinition
            && ! $this->contactStatusTargetExists($parsed)
        ) {
            yield $context->error(
                code: 'flow_routes.contact_status_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references an unavailable ContactStatus target.",
                path: "{$context->path}.definition",
                context: [
                    'point_key' => $context->pointKey,
                    'contact_status_id' => $parsed->contactStatusId,
                    'contact_status_key' => $parsed->contactStatusKey,
                ],
            );
        }

        if (! $parsed instanceof BranchEvaluatePointDefinition) {
            return;
        }

        foreach ($parsed->branches as $branchIndex => $branch) {
            $target = $this->nullableString(
                $branch['target_flow_route_point_key'] ?? null,
            );

            if ($target !== null
                && ! in_array($target, $context->siblingKeys, true)
            ) {
                yield $context->error(
                    code: 'flow_routes.branch_target_missing',
                    message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] branch references missing route point [{$target}].",
                    path: "{$context->path}.definition.branches.{$branchIndex}.target_flow_route_point_key",
                    context: [
                        'point_key' => $context->pointKey,
                        'target_flow_route_point_key' => $target,
                    ],
                );
            }
        }

        if ($parsed->defaultTargetFlowRoutePointKey !== null
            && ! in_array($parsed->defaultTargetFlowRoutePointKey, $context->siblingKeys, true)
        ) {
            yield $context->error(
                code: 'flow_routes.branch_default_target_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references missing default branch target [{$parsed->defaultTargetFlowRoutePointKey}].",
                path: "{$context->path}.definition.default_target_flow_route_point_key",
                context: [
                    'point_key' => $context->pointKey,
                    'target_flow_route_point_key' => $parsed->defaultTargetFlowRoutePointKey,
                ],
            );
        }
    }

    private function waitSchema(): ConfigSchema
    {
        $timingFields = [
            'resume_at',
            'seconds',
            'minutes',
            'hours',
            'days',
            'weeks',
        ];

        return ConfigSchema::object([
            'resume_at' => ConfigField::optional(ConfigSchema::string()),
            'timezone' => ConfigField::optional(ConfigSchema::string()),
            'seconds' => ConfigField::optional(ConfigSchema::integer()),
            'minutes' => ConfigField::optional(ConfigSchema::integer()),
            'hours' => ConfigField::optional(ConfigSchema::integer()),
            'days' => ConfigField::optional(ConfigSchema::integer()),
            'weeks' => ConfigField::optional(ConfigSchema::integer()),
        ], atLeastOne: [
            $timingFields,
        ], atMostOne: [
            $timingFields,
        ]);
    }

    private function contactStatusTargetExists(
        ChangeStatusPointDefinition $definition,
    ): bool {
        $query = ContactStatus::query()->active();

        if ($definition->contactStatusId !== null) {
            return $query->whereKey($definition->contactStatusId)->exists();
        }

        if ($definition->contactStatusKey !== null) {
            return $query->where('key', $definition->contactStatusKey)->exists();
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function openSchema(): ConfigSchema
    {
        return ConfigSchema::object([], allowUnknown: true);
    }
}
