<?php

namespace App\Modules\Tasks\Automation;

use App\Modules\Tasks\Data\Automation\CreateTaskAutomationDefinition;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Services\TaskAssignmentStrategyResolver;
use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class TasksAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    public function __construct(
        private readonly TaskAssignmentStrategyResolver $assignmentStrategies,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationPointDefinition(
            pointType: 'create_task',
            schema: ConfigSchema::object([
                'task_template_key' => ConfigField::required(
                    ConfigSchema::string(),
                    referenceTarget: 'task_templates',
                ),
                'title' => ConfigField::optional(ConfigSchema::string()),
                'assigned_to_id' => ConfigField::optional(ConfigSchema::integer()),
                'assigned_to_type' => ConfigField::optional(ConfigSchema::string()),
                'assigned_to_strategy' => ConfigField::optional(ConfigSchema::string()),
                'responsible_party' => ConfigField::optional(
                    ConfigSchema::string(allowedValues: Task::RESPONSIBLE_PARTY_OPTIONS),
                ),
                'responsible_type' => ConfigField::optional(ConfigSchema::string()),
                'responsible_id' => ConfigField::optional(ConfigSchema::integer()),
                'description' => ConfigField::optional(ConfigSchema::string()),
                'due_at' => ConfigField::optional(ConfigSchema::mixed()),
                'due_offset_minutes' => ConfigField::optional(ConfigSchema::integer()),
                'priority' => ConfigField::optional(ConfigSchema::string()),
                'meta' => ConfigField::defaulted($this->openSchema(), []),
            ]),
        );
    }

    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        if ($pointType !== 'create_task') {
            return;
        }

        $parsed = CreateTaskAutomationDefinition::from(
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

        if (! TaskTemplate::query()
            ->where('key', $parsed->taskTemplateKey)
            ->where('is_active', true)
            ->exists()
        ) {
            yield $context->error(
                code: 'flow_routes.task_template_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references unavailable TaskTemplate [{$parsed->taskTemplateKey}].",
                path: "{$context->path}.definition.task_template_key",
                context: [
                    'point_key' => $context->pointKey,
                    'task_template_key' => $parsed->taskTemplateKey,
                ],
            );
        }

        if ($parsed->assignedToStrategy !== null
            && ! $this->assignmentStrategies->supports($parsed->assignedToStrategy)
        ) {
            yield $context->error(
                code: 'flow_routes.task_assignment_strategy_unavailable',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] requires unavailable Task assignment strategy [{$parsed->assignedToStrategy}].",
                path: "{$context->path}.definition.assigned_to_strategy",
                context: [
                    'point_key' => $context->pointKey,
                    'assignment_strategy' => $parsed->assignedToStrategy,
                ],
            );
        }
    }

    private function openSchema(): ConfigSchema
    {
        return ConfigSchema::object([], allowUnknown: true);
    }
}
