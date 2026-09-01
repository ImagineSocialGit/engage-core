<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class AppointmentTaskAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    public function definitions(): iterable
    {
        yield new AutomationPointDefinition(
            pointType: 'create_appointment_task',
            schema: ConfigSchema::object([
                'task_template_key' => ConfigField::required(
                    ConfigSchema::string(),
                    referenceTarget: 'task_templates',
                ),
                'offset_minutes' => ConfigField::required(ConfigSchema::integer()),
                'assign_to_host' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            ]),
        );
    }

    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        if ($pointType !== 'create_appointment_task') {
            return;
        }

        $resolved = array_replace_recursive($definition, $settings);
        $templateKey = trim((string) ($resolved['task_template_key'] ?? ''));

        if (! TaskTemplate::query()->active()->where('key', $templateKey)->exists()) {
            yield $context->error(
                code: 'flow_routes.task_template_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references unavailable TaskTemplate [{$templateKey}].",
                path: "{$context->path}.definition.task_template_key",
                context: ['point_key' => $context->pointKey, 'task_template_key' => $templateKey],
            );
        }

        $offset = $resolved['offset_minutes'] ?? null;

        if (! is_numeric($offset) || abs((int) $offset) > 525600) {
            yield $context->error(
                code: 'flow_routes.appointment_task_offset_invalid',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] requires an appointment offset no greater than one year.",
                path: "{$context->path}.definition.offset_minutes",
                context: ['point_key' => $context->pointKey],
            );
        }
    }
}