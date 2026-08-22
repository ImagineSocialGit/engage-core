<?php

namespace App\Modules\Relationships\Automation;

use App\Modules\Relationships\Data\Automation\ChangeRelationshipStageAutomationDefinition;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class RelationshipStageAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    public function __construct(
        private readonly RelationshipDefinitionRegistry $relationships,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationPointDefinition(
            pointType: 'change_relationship_stage',
            schema: ConfigSchema::object([
                'relationship_key' => ConfigField::required(ConfigSchema::string()),
                'stage_key' => ConfigField::required(ConfigSchema::string()),
                'on_missing_relationship' => ConfigField::defaulted(
                    ConfigSchema::string(
                        allowedValues: ChangeRelationshipStageAutomationDefinition::ON_MISSING_RELATIONSHIP_OPTIONS,
                    ),
                    ChangeRelationshipStageAutomationDefinition::ON_MISSING_RELATIONSHIP_SKIPPED,
                ),
            ]),
        );
    }

    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        if ($pointType !== 'change_relationship_stage') {
            return;
        }

        $parsed = ChangeRelationshipStageAutomationDefinition::from(
            array_replace_recursive($definition, $settings),
        );

        if (! $parsed->isValid()) {
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

        if (! $this->relationships->has($parsed->relationshipKey)) {
            yield $context->error(
                code: 'flow_routes.relationship_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references missing Contact relationship [{$parsed->relationshipKey}].",
                path: "{$context->path}.definition.relationship_key",
                context: [
                    'point_key' => $context->pointKey,
                    'relationship_key' => $parsed->relationshipKey,
                ],
            );

            return;
        }

        $relationship = $this->relationships->get($parsed->relationshipKey);
        $stage = $relationship['stages'][$parsed->stageKey] ?? null;

        if (! is_array($stage)) {
            yield $context->error(
                code: 'flow_routes.relationship_stage_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references missing stage [{$parsed->stageKey}] for Contact relationship [{$parsed->relationshipKey}].",
                path: "{$context->path}.definition.stage_key",
                context: [
                    'point_key' => $context->pointKey,
                    'relationship_key' => $parsed->relationshipKey,
                    'stage_key' => $parsed->stageKey,
                ],
            );

            return;
        }

        if (! (bool) ($stage['active'] ?? false)) {
            yield $context->error(
                code: 'flow_routes.relationship_stage_inactive',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references inactive stage [{$parsed->stageKey}] for Contact relationship [{$parsed->relationshipKey}].",
                path: "{$context->path}.definition.stage_key",
                context: [
                    'point_key' => $context->pointKey,
                    'relationship_key' => $parsed->relationshipKey,
                    'stage_key' => $parsed->stageKey,
                ],
            );
        }
    }
}