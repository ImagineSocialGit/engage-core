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
        $empty = ConfigSchema::object([]);
        $pointOptions = [];

        foreach ($this->pointDefinitions->definitions() as $pointType => $definition) {
            $pointOptions[] = $this->pointSchema(
                type: $pointType,
                definitionSchema: $definition->schema,
                openSchema: $open,
                emptySchema: $empty,
            );
        }

        return ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'contact_status_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
                referenceTarget: 'contact_statuses',
            ),
            'name' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'version' => ConfigField::defaulted(ConfigSchema::integer(), 1),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'source_version' => ConfigField::optional($this->sourceVersionSchema()),
            'owner_type' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'owner_id' => ConfigField::optional(ConfigSchema::integer(nullable: true)),
            'owner_group' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'trigger' => ConfigField::optional($this->triggerSchema()),
            'points' => ConfigField::required(
                ConfigSchema::listOf(ConfigSchema::oneOf($pointOptions)),
            ),
            'meta' => ConfigField::defaulted($open, []),
        ], atLeastOne: [
            ['contact_status_key', 'trigger'],
        ], atMostOne: [
            ['contact_status_key', 'trigger'],
        ]);
    }

    public function example(): array
    {
        return [
            'key' => 'example',
            'name' => 'Example',
            'trigger' => [
                'type' => 'manual',
            ],
            'points' => [
                [
                    'key' => 'start',
                    'type' => 'noop',
                    'is_start' => true,
                ],
            ],
        ];
    }

    private function pointSchema(
        string $type,
        ConfigSchema $definitionSchema,
        ConfigSchema $openSchema,
        ConfigSchema $emptySchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'type' => ConfigField::required(
                ConfigSchema::string(allowedValues: [$type]),
            ),
            'name' => ConfigField::optional(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'capability_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
                referenceTarget: 'flow_route_capabilities',
            ),
            'sort_order' => ConfigField::optional(ConfigSchema::integer()),
            'is_start' => ConfigField::defaulted(ConfigSchema::boolean(), false),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'next_point_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
                referenceTarget: 'flow_route_points',
            ),
            'definition' => ConfigField::defaulted($definitionSchema, []),
            'settings' => ConfigField::defaulted($emptySchema, []),
            'cancel_conditions' => ConfigField::defaulted(
                ConfigSchema::listOf($openSchema),
                [],
            ),
            'source_version' => ConfigField::optional($this->sourceVersionSchema()),
            'meta' => ConfigField::defaulted($openSchema, []),
        ]);
    }

    private function triggerSchema(): ConfigSchema
    {
        return ConfigSchema::oneOf([
            ConfigSchema::object([
                'type' => ConfigField::required(
                    ConfigSchema::string(allowedValues: ['manual']),
                ),
            ]),
            ConfigSchema::object([
                'type' => ConfigField::required(
                    ConfigSchema::string(allowedValues: ['automation_event']),
                ),
                'event_key' => ConfigField::required(
                    ConfigSchema::string(),
                    referenceTarget: 'automation_events',
                ),
            ]),
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
