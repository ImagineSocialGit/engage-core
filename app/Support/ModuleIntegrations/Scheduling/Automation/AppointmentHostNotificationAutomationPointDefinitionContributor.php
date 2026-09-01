<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class AppointmentHostNotificationAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    public function definitions(): iterable
    {
        yield new AutomationPointDefinition(
            pointType: 'notify_appointment_host',
            schema: ConfigSchema::object([
                'offset_minutes' => ConfigField::required(ConfigSchema::integer()),
                'subject' => ConfigField::required(ConfigSchema::string()),
                'message' => ConfigField::required(ConfigSchema::string()),
            ]),
        );
    }

    public function validate(string $pointType, array $definition, array $settings, AutomationPointValidationContext $context): iterable
    {
        if ($pointType !== 'notify_appointment_host') {
            return;
        }

        $resolved = array_replace_recursive($definition, $settings);
        $offset = $resolved['offset_minutes'] ?? null;

        if (! is_numeric($offset) || abs((int) $offset) > 525600) {
            yield $context->error(
                code: 'flow_routes.appointment_host_notification_offset_invalid',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] requires a host-notification offset no greater than one year.",
                path: "{$context->path}.definition.offset_minutes",
                context: ['point_key' => $context->pointKey],
            );
        }
    }
}