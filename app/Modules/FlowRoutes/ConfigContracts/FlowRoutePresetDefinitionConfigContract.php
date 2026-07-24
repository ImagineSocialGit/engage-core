<?php

namespace App\Modules\FlowRoutes\ConfigContracts;

use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class FlowRoutePresetDefinitionConfigContract implements ConfigContract
{
    public function __construct(
        private readonly AutomationPointDefinitionRegistry $pointDefinitions,
    ) {}

    public function key(): string
    {
        return 'flow_routes.preset_definition';
    }

    public function owner(): string
    {
        return 'flow_routes';
    }

    public function sourcePattern(): string
    {
        return 'presets.modules.{contributor}.flow-routes.definitions.{route_key}';
    }

    public function schema(): ConfigSchema
    {
        $open = $this->openSchema();
        $pointOptions = [];

        foreach ($this->pointDefinitions->definitions() as $pointType => $definition) {
            $pointOptions[] = $this->pointSchema(
                type: $pointType,
                definitionSchema: $definition->schema,
                openSchema: $open,
            );
        }

        return ConfigSchema::object([
            'contact_status_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
                referenceTarget: 'contact_statuses',
            ),
            'event_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
                referenceTarget: 'automation_events',
            ),
            'name' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'version' => ConfigField::defaulted(ConfigSchema::integer(), 1),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'source_version' => ConfigField::optional($this->sourceVersionSchema()),
            'owner_type' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'owner_id' => ConfigField::optional(ConfigSchema::integer(nullable: true)),
            'owner_group' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'category' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'role' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'points' => ConfigField::required(
                ConfigSchema::mapOf(ConfigSchema::oneOf($pointOptions)),
            ),
        ], atMostOne: [
            ['contact_status_key', 'event_key'],
        ]);
    }

    public function example(): array
    {
        return [
            'name' => 'Example',
            'points' => [
                'start' => [
                    'type' => 'noop',
                ],
            ],
        ];
    }

    private function pointSchema(
        string $type,
        ConfigSchema $definitionSchema,
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'type' => ConfigField::required(
                ConfigSchema::string(allowedValues: [$type]),
            ),
            'name' => ConfigField::optional(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'definition' => ConfigField::defaulted($definitionSchema, []),
            'cancel_conditions' => ConfigField::optional(
                ConfigSchema::listOf($openSchema),
            ),
        ]);
    }

    private function sourceVersionSchema(): ConfigSchema
    {
        return ConfigSchema::oneOf([
            ConfigSchema::string(),
            ConfigSchema::integer(),
            ConfigSchema::number(),
        ], nullable: true);
    }

    private function openSchema(): ConfigSchema
    {
        return ConfigSchema::object([], allowUnknown: true);
    }
}